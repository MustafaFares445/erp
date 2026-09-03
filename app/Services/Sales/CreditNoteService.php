<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStockConsequence;
use App\Enums\CreditNoteStatus;
use App\Enums\InventoryReturnStatus;
use App\Exceptions\Domain\CreditExceedsReturn;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class CreditNoteService
{
    public function __construct(
        private CreditNotePostingService $posting,
        private JournalPostingService $journalPosting,
        private InvoiceBalanceService $balances,
    ) {}

    public function addLine(
        User $actor,
        CreditNote $creditNote,
        string $description,
        float $quantity,
        float $unitPrice,
        float $taxAmount,
        ?InvoiceLine $invoiceLine = null,
        ?InventoryReturnLine $inventoryReturnLine = null,
    ): CreditNoteLine {
        Gate::forUser($actor)->authorize('update', $creditNote);

        if ($invoiceLine instanceof InvoiceLine
            && $creditNote->invoice_id !== null
            && (int) $invoiceLine->invoice_id !== (int) $creditNote->invoice_id) {
            throw new DomainException('A credit note line must belong to the source invoice.');
        }

        if ($inventoryReturnLine instanceof InventoryReturnLine) {
            $this->assertReturnLineMatchesNote(
                $creditNote,
                $inventoryReturnLine,
                $invoiceLine,
            );

            $remainingReturnQuantity = bcsub(
                $this->returnLineCommercialQuantity($inventoryReturnLine),
                $this->creditedQuantityForReturnLine($inventoryReturnLine),
                6,
            );

            if (bccomp($this->decimalQuantity($quantity), $remainingReturnQuantity, 6) === 1) {
                throw CreditExceedsReturn::make();
            }
        }

        return $creditNote->lines()->create([
            'invoice_line_id' => $invoiceLine?->getKey(),
            'inventory_return_line_id' => $inventoryReturnLine?->getKey(),
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_amount' => $taxAmount,
            'line_total' => round($quantity * $unitPrice + $taxAmount, 2),
            'sort_order' => $creditNote->lines()->count(),
        ]);
    }

    public function removeLine(User $actor, CreditNoteLine $line): void
    {
        $creditNote = $line->creditNote;

        if (! $creditNote instanceof CreditNote) {
            throw new DomainException('This line has no parent credit note.');
        }

        Gate::forUser($actor)->authorize('update', $creditNote);

        $line->delete();
    }

    public function confirm(User $actor, CreditNote $creditNote): CreditNote
    {
        Gate::forUser($actor)->authorize('confirm', $creditNote);

        return DB::transaction(function () use ($actor, $creditNote): CreditNote {
            /** @var CreditNote $locked */
            $locked = CreditNote::query()
                ->with([
                    'lines.invoiceLine',
                    'lines.inventoryReturnLine.originalOperationLine',
                    'invoice',
                    'inventoryReturn',
                ])
                ->whereKey($creditNote->getKey())
                ->lockForUpdate()
                ->sole();

            if ($locked->isConfirmed()) {
                throw new DomainException('This credit note is already confirmed.');
            }

            if ($locked->lines->isEmpty()) {
                throw new DomainException('A credit note requires at least one line.');
            }

            $this->assertStockConsequence($locked);

            $returnLineIds = $locked->lines
                ->pluck('inventory_return_line_id')
                ->filter(fn (mixed $id): bool => is_numeric($id))
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($returnLineIds !== []) {
                InventoryReturnLine::query()
                    ->whereKey($returnLineIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            $subtotal = 0.0;
            $tax = 0.0;

            foreach ($locked->lines as $line) {
                $this->assertLineWithinRemaining($locked, $line);
                $this->assertLineWithinReturnRemaining($locked, $line);
                $subtotal += (float) $line->line_total - (float) $line->tax_amount;
                $tax += (float) $line->tax_amount;
            }

            $subtotal = round($subtotal, 2);
            $tax = round($tax, 2);
            $total = round($subtotal + $tax, 2);

            /** @var Invoice|null $invoice */
            $invoice = $locked->invoice;

            if ($invoice instanceof Invoice) {
                /** @var Invoice $invoice */
                $invoice = Invoice::query()
                    ->with('confirmations')
                    ->whereKey($invoice->getKey())
                    ->lockForUpdate()
                    ->sole();

                if (! $invoice->isIssued()) {
                    throw new DomainException('A credit note can only correct an issued invoice.');
                }

                if ((int) $invoice->customer_id !== (int) $locked->customer_id) {
                    throw new DomainException('The credit note customer must match its invoice.');
                }

                $remaining = max(0.0, (float) $invoice->total_amount - (float) $invoice->credited_amount);
                if ($total - $remaining > 0.00001) {
                    throw new DomainException('The credit note exceeds the invoice uncredited balance.');
                }
            }

            $locked->forceFill([
                'subtotal' => $subtotal,
                'tax_total' => $tax,
                'grand_total' => $total,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->posting->post($actor, $locked, $invoice);

            if ($invoice instanceof Invoice) {
                $invoice->forceFill([
                    'credited_amount' => round((float) $invoice->credited_amount + $total, 2),
                ])->save();

                $this->balances->syncInvoice($invoice);
                $this->balances->syncOrder($invoice->order);
            }

            $locked->forceFill([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges(['attributes' => ['status' => 'confirmed']])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('sales.credit_note.confirmed');

            return $locked->refresh();
        }, attempts: 5);
    }

    public function reverse(User $actor, CreditNote $creditNote): CreditNote
    {
        Gate::forUser($actor)->authorize('reverse', $creditNote);

        return DB::transaction(function () use ($actor, $creditNote): CreditNote {
            /** @var CreditNote $locked */
            $locked = CreditNote::query()
                ->with(['invoice', 'journalEntries'])
                ->whereKey($creditNote->getKey())
                ->lockForUpdate()
                ->sole();

            if (! $locked->isConfirmed() || $locked->isReversed()) {
                throw new DomainException('Only an unreversed confirmed credit note can be reversed.');
            }

            foreach ($locked->journalEntries as $entry) {
                if ($entry instanceof JournalEntry && $entry->isPosted()) {
                    $this->journalPosting->reverse(
                        $actor,
                        $entry,
                        CarbonImmutable::today(),
                        "Reverse credit note {$locked->credit_note_number}",
                    );
                }
            }

            if ($locked->invoice instanceof Invoice) {
                /** @var Invoice $invoice */
                $invoice = Invoice::query()
                    ->with('confirmations')
                    ->whereKey($locked->invoice->getKey())
                    ->lockForUpdate()
                    ->sole();

                $invoice->forceFill([
                    'credited_amount' => max(
                        0.0,
                        round((float) $invoice->credited_amount - (float) $locked->grand_total, 2),
                    ),
                ])->save();

                $this->balances->syncInvoice($invoice);
                $this->balances->syncOrder($invoice->order);
            }

            $locked->forceFill([
                'status' => 'reversed',
                'reversed_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges(['attributes' => ['status' => 'reversed']])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('sales.credit_note.reversed');

            return $locked->refresh();
        }, attempts: 5);
    }

    public function creditedQuantityForReturnLine(InventoryReturnLine $line): string
    {
        $quantity = CreditNoteLine::query()
            ->where('inventory_return_line_id', $line->getKey())
            ->whereHas('creditNote', fn ($query) => $query
                ->where('status', CreditNoteStatus::Confirmed->value))
            ->sum('quantity');

        return $this->decimalQuantity(is_numeric($quantity) ? (float) $quantity : 0.0);
    }

    private function assertStockConsequence(CreditNote $note): void
    {
        $consequence = $note->stock_consequence;

        if (! $consequence instanceof CreditNoteStockConsequence) {
            throw new DomainException('A credit note requires an explicit stock consequence.');
        }

        if ($note->reason_category === CreditNoteReason::SalesReturn
            && $consequence === CreditNoteStockConsequence::NotApplicable) {
            throw new DomainException(
                'A sales-return credit must state whether goods were returned or retained by the customer.',
            );
        }

        if (! $consequence->requiresReturnLink()) {
            if ($consequence === CreditNoteStockConsequence::CustomerRetained
                && $note->inventory_return_id !== null) {
                throw new DomainException(
                    'A customer-retained credit cannot link to an inventory return.',
                );
            }

            return;
        }

        $return = $note->inventoryReturn;

        if (
            ! $return instanceof InventoryReturn
            || $return->status !== InventoryReturnStatus::Posted
            || (int) $return->customer_id !== (int) $note->customer_id
        ) {
            throw new DomainException(
                'Goods-returned credit notes require a posted return for the same customer.',
            );
        }

        foreach ($note->lines as $line) {
            if (! $line->inventoryReturnLine instanceof InventoryReturnLine) {
                throw new DomainException(
                    'Every goods-returned credit line must link to an inventory return line.',
                );
            }

            $this->assertReturnLineMatchesNote(
                $note,
                $line->inventoryReturnLine,
                $line->invoiceLine,
            );
        }
    }

    private function assertReturnLineMatchesNote(
        CreditNote $note,
        InventoryReturnLine $returnLine,
        ?InvoiceLine $invoiceLine,
    ): void {
        if (
            $note->inventory_return_id === null
            || (int) $returnLine->inventory_return_id !== (int) $note->inventory_return_id
        ) {
            throw new DomainException(
                'A credit note return line must belong to the selected inventory return.',
            );
        }

        if (! $invoiceLine instanceof InvoiceLine) {
            throw new DomainException(
                'A returned-goods credit line must identify its source invoice line.',
            );
        }

        if (
            $invoiceLine->product_variant_id !== null
            && (int) $invoiceLine->product_variant_id !== (int) $returnLine->product_variant_id
        ) {
            throw new DomainException(
                'The credited invoice line and inventory return line refer to different product variants.',
            );
        }

        $deliveryLine = $returnLine->originalOperationLine;

        if (
            $deliveryLine !== null
            && $invoiceLine->order_line_id !== null
            && $deliveryLine->order_line_id !== null
            && (int) $invoiceLine->order_line_id !== (int) $deliveryLine->order_line_id
        ) {
            throw new DomainException(
                'The credited invoice line does not match the returned delivery line.',
            );
        }
    }

    private function assertLineWithinReturnRemaining(
        CreditNote $note,
        CreditNoteLine $line,
    ): void {
        if ($line->inventory_return_line_id === null) {
            return;
        }

        $returnLine = InventoryReturnLine::query()
            ->with('originalOperationLine')
            ->whereKey($line->inventory_return_line_id)
            ->lockForUpdate()
            ->first();

        if (! $returnLine instanceof InventoryReturnLine) {
            throw new DomainException('The linked inventory return line no longer exists.');
        }

        $this->assertReturnLineMatchesNote($note, $returnLine, $line->invoiceLine);

        $alreadyCredited = CreditNoteLine::query()
            ->where('inventory_return_line_id', $returnLine->getKey())
            ->where('credit_note_id', '!=', $note->getKey())
            ->whereHas('creditNote', fn ($query) => $query
                ->where('status', CreditNoteStatus::Confirmed->value))
            ->sum('quantity');

        $remaining = bcsub(
            $this->returnLineCommercialQuantity($returnLine),
            $this->decimalQuantity(is_numeric($alreadyCredited) ? (float) $alreadyCredited : 0.0),
            6,
        );

        if (bccomp($this->decimalQuantity((float) $line->quantity), $remaining, 6) === 1) {
            throw CreditExceedsReturn::make();
        }
    }

    /** @return numeric-string */
    private function returnLineCommercialQuantity(InventoryReturnLine $line): string
    {
        $quantity = (string) $line->transaction_quantity;

        if (! is_numeric($quantity)) {
            throw new DomainException('Inventory return transaction quantity must be numeric.');
        }

        return bcadd($quantity, '0', 6);
    }

    /** @return numeric-string */
    private function decimalQuantity(float $quantity): string
    {
        if ($quantity < 0) {
            throw new DomainException('Credit quantities cannot be negative.');
        }

        return number_format($quantity, 6, '.', '');
    }

    private function assertLineWithinRemaining(CreditNote $note, CreditNoteLine $line): void
    {
        if ($line->invoice_line_id === null) {
            return;
        }

        $invoiceLine = $line->invoiceLine;
        if (! $invoiceLine instanceof InvoiceLine) {
            throw new DomainException('The referenced invoice line no longer exists.');
        }

        if ($note->invoice_id !== null && (int) $invoiceLine->invoice_id !== (int) $note->invoice_id) {
            throw new DomainException('A credit note line must belong to the source invoice.');
        }

        $confirmedLines = CreditNoteLine::query()
            ->where('invoice_line_id', $invoiceLine->getKey())
            ->whereHas('creditNote', function ($query) use ($note): void {
                $query->where('status', 'confirmed')->whereKeyNot($note->getKey());
            });

        $creditedValue = (float) (clone $confirmedLines)->sum('line_total');
        $creditedQuantity = (float) (clone $confirmedLines)->sum('quantity');

        $remainingValue = max(0.0, (float) $invoiceLine->line_total - $creditedValue);
        $remainingQuantity = max(0.0, (float) $invoiceLine->quantity - $creditedQuantity);

        if ((float) $line->line_total - $remainingValue > 0.00001) {
            throw new DomainException('A credit note line exceeds the invoice line uncredited value.');
        }

        if ((float) $line->quantity - $remainingQuantity > 0.000001) {
            throw new DomainException('A credit note line exceeds the invoice line uncredited quantity.');
        }
    }
}
