<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\BillStatus;
use App\Enums\InventoryReturnStatus;
use App\Enums\InventoryReturnType;
use App\Enums\SupplierDebitNoteStatus;
use App\Enums\SupplierReturnExpectedOutcome;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ChartAccount;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\JournalEntry;
use App\Models\PurchaseSetting;
use App\Models\SupplierDebitNote;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * WP-4.8 commercial leg of a physical supplier return.
 *
 * A debit note is derived from the exact return-line -> receipt-line -> PO-line
 * -> bill-line chain. No amount may be keyed independently of that provenance.
 */
final readonly class SupplierDebitNoteService
{
    private const int QUANTITY_SCALE = 6;

    private const int RATIO_SCALE = 10;

    public function __construct(private JournalPostingService $journalPosting) {}

    public function setExpectedOutcome(
        User $actor,
        InventoryReturn $return,
        SupplierReturnExpectedOutcome $outcome,
    ): InventoryReturn {
        Gate::forUser($actor)->authorize('update', $return);

        if ($return->return_type !== InventoryReturnType::Supplier || ! $return->isDraft()) {
            throw new DomainException('A supplier return outcome can only be selected while the supplier return is draft.');
        }

        $return->forceFill([
            'expected_outcome' => $outcome,
            'updated_by' => $actor->getKey(),
        ])->save();

        return $return->refresh();
    }

    public function createForReturn(User $actor, InventoryReturn $return, Bill $bill, ?string $notes = null): SupplierDebitNote
    {
        Gate::forUser($actor)->authorize('create', SupplierDebitNote::class);

        return DB::transaction(function () use ($actor, $return, $bill, $notes): SupplierDebitNote {
            /** @var InventoryReturn $lockedReturn */
            $lockedReturn = InventoryReturn::query()
                ->with(['lines.originalOperationLine'])
                ->whereKey($return->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Bill $lockedBill */
            $lockedBill = Bill::query()
                ->with('lines')
                ->whereKey($bill->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCommerciallyCreditable($lockedReturn, $lockedBill);

            if (SupplierDebitNote::query()->where('inventory_return_id', $lockedReturn->getKey())->exists()) {
                throw new DomainException('This supplier return already has a debit note.');
            }

            $note = SupplierDebitNote::query()->create([
                'debit_note_number' => $this->nextNumber(),
                'supplier_id' => $lockedReturn->supplier_id,
                'inventory_return_id' => $lockedReturn->getKey(),
                'bill_id' => $lockedBill->getKey(),
                'issue_date' => now()->toDateString(),
                'status' => SupplierDebitNoteStatus::Draft,
                'notes' => $notes,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $subtotalMinor = 0;
            $taxMinor = 0;

            foreach ($lockedReturn->lines as $returnLine) {
                $derived = $this->deriveLine($returnLine, $lockedBill);
                $note->lines()->create($derived['attributes']);
                $subtotalMinor += $derived['subtotal_minor'];
                $taxMinor += $derived['tax_minor'];
            }

            if ($subtotalMinor <= 0) {
                throw new DomainException('A supplier debit note requires at least one financially traceable return line.');
            }

            $note->forceFill([
                'subtotal' => self::money($subtotalMinor),
                'tax_total' => self::money($taxMinor),
                'total_amount' => self::money($subtotalMinor + $taxMinor),
            ])->save();

            activity()
                ->performedOn($note)
                ->causedBy($actor)
                ->withProperties([
                    'inventory_return_id' => $lockedReturn->getKey(),
                    'bill_id' => $lockedBill->getKey(),
                    'expected_outcome' => $lockedReturn->expected_outcome?->value,
                ])
                ->log('purchasing.supplier_debit_note.created');

            return $note->refresh()->load('lines');
        });
    }

    public function confirm(User $actor, SupplierDebitNote $note): SupplierDebitNote
    {
        Gate::forUser($actor)->authorize('confirm', $note);

        return DB::transaction(function () use ($actor, $note): SupplierDebitNote {
            /** @var SupplierDebitNote $locked */
            $locked = SupplierDebitNote::query()
                ->with(['lines', 'bill', 'inventoryReturn'])
                ->whereKey($note->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== SupplierDebitNoteStatus::Draft) {
                throw new DomainException('Only a draft supplier debit note can be confirmed.');
            }

            $bill = $locked->bill;
            if (! $bill instanceof Bill) {
                throw new DomainException('The supplier debit note must reference a bill.');
            }

            $bill = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();
            $noteMinor = self::minor((string) $locked->total_amount);
            $grossMinor = self::minor($bill->originalGrandTotal());
            $existingCreditMinor = self::minor((string) $bill->supplier_credit_total);

            if ($noteMinor <= 0 || $existingCreditMinor + $noteMinor > $grossMinor) {
                throw new DomainException('Supplier debit notes cannot credit more than the original supplier bill total.');
            }

            $grni = PurchaseSetting::current()->load('grniAccount')->grniAccount;
            if (! $grni instanceof ChartAccount || ! $grni->is_active || ! $grni->is_postable) {
                throw new DomainException('A postable GRNI account must be configured before confirming a supplier debit note.');
            }

            $payable = $this->accountByCode('2100', 'Accounts payable');
            $inputTax = $this->accountByCode('1450', 'Recoverable input tax');
            $subtotal = (string) $locked->subtotal;
            $tax = (string) $locked->tax_total;
            $total = (string) $locked->total_amount;

            $postingLines = [
                [
                    'chart_account_id' => (int) $payable->getKey(),
                    'debit' => $total,
                    'credit' => '0.00',
                    'description' => 'Reduce supplier payable',
                ],
                [
                    'chart_account_id' => (int) $grni->getKey(),
                    'debit' => '0.00',
                    'credit' => $subtotal,
                    'description' => 'Reverse supplier-return purchase value',
                ],
            ];

            if (self::minor($tax) > 0) {
                $postingLines[] = [
                    'chart_account_id' => (int) $inputTax->getKey(),
                    'debit' => '0.00',
                    'credit' => $tax,
                    'description' => 'Reverse recoverable input tax',
                ];
            }

            $journal = $this->journalPosting->postNew(
                $actor,
                CarbonImmutable::parse($locked->issue_date),
                $postingLines,
                'Supplier debit note '.$locked->debit_note_number,
                $locked,
            );

            if (self::minor($tax) > 0) {
                TaxRecognitionEntry::query()->create([
                    'tax_date' => $locked->issue_date->toDateString(),
                    'direction' => 'input',
                    'tax_type' => 'supplier_debit_note',
                    'tax_amount' => self::money(-self::minor($tax)),
                    'source_type' => $locked->getMorphClass(),
                    'source_id' => $locked->getKey(),
                    'journal_entry_id' => $journal->getKey(),
                ]);
            }

            $bill->forceFill([
                'supplier_credit_total' => self::money($existingCreditMinor + $noteMinor),
            ])->save();

            $locked->forceFill([
                'status' => SupplierDebitNoteStatus::Confirmed,
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties([
                    'journal_entry_id' => $journal->getKey(),
                    'bill_id' => $bill->getKey(),
                    'supplier_credit_total' => $bill->supplier_credit_total,
                ])
                ->log('purchasing.supplier_debit_note.confirmed');

            return $locked->refresh();
        });
    }

    public function reverse(User $actor, SupplierDebitNote $note, ?CarbonImmutable $date = null): SupplierDebitNote
    {
        Gate::forUser($actor)->authorize('reverse', $note);

        return DB::transaction(function () use ($actor, $note, $date): SupplierDebitNote {
            /** @var SupplierDebitNote $locked */
            $locked = SupplierDebitNote::query()
                ->with(['journalEntries', 'bill'])
                ->whereKey($note->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== SupplierDebitNoteStatus::Confirmed) {
                throw new DomainException('Only a confirmed supplier debit note can be reversed.');
            }

            $journal = $locked->journalEntries()->where('status', 'posted')->orderBy('id')->first();
            if (! $journal instanceof JournalEntry) {
                throw new DomainException('The supplier debit note posting cannot be resolved.');
            }

            $reversal = $this->journalPosting->reverse(
                $actor,
                $journal,
                $date ?? CarbonImmutable::today(),
                'Reverse supplier debit note '.$locked->debit_note_number,
            );

            $taxMinor = self::minor((string) $locked->tax_total);
            if ($taxMinor > 0) {
                TaxRecognitionEntry::query()->create([
                    'tax_date' => ($date ?? CarbonImmutable::today())->toDateString(),
                    'direction' => 'input',
                    'tax_type' => 'supplier_debit_note_reversal',
                    'tax_amount' => self::money($taxMinor),
                    'source_type' => $reversal->getMorphClass(),
                    'source_id' => $reversal->getKey(),
                    'journal_entry_id' => $reversal->getKey(),
                ]);
            }

            $bill = Bill::query()->whereKey($locked->bill_id)->lockForUpdate()->firstOrFail();
            $currentCreditMinor = self::minor((string) $bill->supplier_credit_total);
            $noteMinor = self::minor((string) $locked->total_amount);
            $bill->forceFill([
                'supplier_credit_total' => self::money(max(0, $currentCreditMinor - $noteMinor)),
            ])->save();

            $locked->forceFill([
                'status' => SupplierDebitNoteStatus::Reversed,
                'reversed_by' => $actor->getKey(),
                'reversed_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties(['reversal_journal_entry_id' => $reversal->getKey()])
                ->log('purchasing.supplier_debit_note.reversed');

            return $locked->refresh();
        });
    }

    /** @return Builder<InventoryReturn> */
    public function awaitingSupplierCreditQuery(): Builder
    {
        return InventoryReturn::query()
            ->where('return_type', InventoryReturnType::Supplier->value)
            ->where('status', InventoryReturnStatus::Posted->value)
            ->whereIn('expected_outcome', ['credit', 'refund'])
            ->whereDoesntHave('supplierDebitNote', fn ($query) => $query->where('status', SupplierDebitNoteStatus::Confirmed->value));
    }

    private function assertCommerciallyCreditable(InventoryReturn $return, Bill $bill): void
    {
        if ($return->return_type !== InventoryReturnType::Supplier || $return->status !== InventoryReturnStatus::Posted) {
            throw new DomainException('Supplier debit notes require a posted supplier return.');
        }

        if ($return->expected_outcome === null || ! $return->expected_outcome->requiresFinancialCredit()) {
            throw new DomainException('The supplier return must expect a credit or refund before a debit note can be created.');
        }

        if ((int) $return->supplier_id !== (int) $bill->supplier_id) {
            throw new DomainException('The supplier return and bill must belong to the same supplier.');
        }

        if ($return->original_purchase_order_id !== null
            && (int) $return->original_purchase_order_id !== (int) $bill->purchase_order_id) {
            throw new DomainException('The supplier debit note bill must belong to the return purchase order.');
        }

        if (! in_array($bill->status, [BillStatus::Approved, BillStatus::PartiallyPaid, BillStatus::Paid], true)) {
            throw new DomainException('Supplier debit notes can only reference an approved or paid bill.');
        }
    }

    /** @return array{attributes: array<string, mixed>, subtotal_minor: int, tax_minor: int} */
    private function deriveLine(InventoryReturnLine $returnLine, Bill $bill): array
    {
        $receiptLine = $returnLine->originalOperationLine;
        if (! $receiptLine instanceof InventoryOperationLine || ! is_int($receiptLine->purchase_order_line_id)) {
            throw new DomainException('Every financially credited supplier-return line must resolve to its original purchase-order line.');
        }

        $billLine = $bill->lines->first(
            fn (BillLine $line): bool => (int) $line->purchase_order_line_id === $receiptLine->purchase_order_line_id,
        );

        if (! $billLine instanceof BillLine) {
            throw new DomainException('The selected bill does not contain a line for every returned purchase-order line.');
        }

        $factor = (string) ($receiptLine->conversion_factor_snapshot ?? '1.000000');
        if (bccomp($factor, '0', self::QUANTITY_SCALE) <= 0) {
            throw new DomainException('The receipt conversion snapshot is invalid for supplier debit-note derivation.');
        }

        $transactionQuantity = bcdiv((string) $returnLine->base_quantity, $factor, self::QUANTITY_SCALE);
        $billedQuantity = (string) $billLine->quantity;
        if (bccomp($billedQuantity, '0', self::QUANTITY_SCALE) <= 0
            || bccomp($transactionQuantity, $billedQuantity, self::QUANTITY_SCALE) > 0) {
            throw new DomainException('The supplier-return quantity exceeds the selected bill-line quantity.');
        }

        $ratio = bcdiv($transactionQuantity, $billedQuantity, self::RATIO_SCALE);
        $subtotalMinor = self::minor(bcmul($transactionQuantity, (string) $billLine->unit_price, 4));
        $taxMinor = self::minor(bcmul((string) $billLine->tax_amount, $ratio, 4));

        return [
            'attributes' => [
                'inventory_return_line_id' => $returnLine->getKey(),
                'bill_line_id' => $billLine->getKey(),
                'product_variant_id' => $returnLine->product_variant_id,
                'description' => 'Supplier return '.$returnLine->inventory_return_id.' / bill line '.$billLine->getKey(),
                'quantity' => $transactionQuantity,
                'unit_price' => $billLine->unit_price,
                'tax_amount' => self::money($taxMinor),
                'line_total' => self::money($subtotalMinor),
            ],
            'subtotal_minor' => $subtotalMinor,
            'tax_minor' => $taxMinor,
        ];
    }

    private function accountByCode(string $code, string $label): ChartAccount
    {
        $account = ChartAccount::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->first();

        if (! $account instanceof ChartAccount) {
            throw new DomainException("{$label} account {$code} must exist, be active, and be postable.");
        }

        return $account;
    }

    private function nextNumber(): string
    {
        $last = SupplierDebitNote::query()->lockForUpdate()->orderByDesc('id')->value('debit_note_number');
        $next = is_string($last) && preg_match('/(\d+)$/', $last, $matches) === 1
            ? ((int) $matches[1]) + 1
            : 1;

        return sprintf('SDN-%07d', $next);
    }

    private static function minor(string|int|float $value): int
    {
        return (int) round((float) $value * 100);
    }

    private static function money(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';

        return $sign.number_format(abs($minor) / 100, 2, '.', '');
    }
}
