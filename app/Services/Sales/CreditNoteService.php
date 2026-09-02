<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
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

    public function confirm(User $actor, CreditNote $creditNote): CreditNote
    {
        Gate::forUser($actor)->authorize('confirm', $creditNote);

        return DB::transaction(function () use ($actor, $creditNote): CreditNote {
            /** @var CreditNote $locked */
            $locked = CreditNote::query()
                ->with(['lines.invoiceLine', 'invoice'])
                ->whereKey($creditNote->getKey())
                ->lockForUpdate()
                ->sole();

            if ($locked->isConfirmed()) {
                throw new DomainException('This credit note is already confirmed.');
            }

            if ($locked->lines->isEmpty()) {
                throw new DomainException('A credit note requires at least one line.');
            }

            $subtotal = 0.0;
            $tax = 0.0;

            foreach ($locked->lines as $line) {
                $this->assertLineWithinRemaining($locked, $line);
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
