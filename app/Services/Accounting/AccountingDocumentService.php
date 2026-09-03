<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\Domain\DuplicateSupplierReference;
use App\Exceptions\Domain\SupplierReferenceRequired;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ChartAccount;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrderLine;
use App\Models\Refund;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Services\Accounting\Support\TaxRecognition;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

final readonly class AccountingDocumentService
{
    public function __construct(private JournalPostingService $journalPosting) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function recordBill(User $actor, array $attributes, array $lines = []): Bill
    {
        Gate::forUser($actor)->authorize('create', Bill::class);

        try {
            return DB::transaction(function () use ($actor, $attributes, $lines): Bill {
                $bill = new Bill($attributes);
                $reference = $this->normalizeSupplierReference($bill);
                $bill->forceFill([
                    'supplier_reference' => $reference,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]);

                $this->lockSupplierForBill($bill);
                $this->assertSupplierReferenceIsAvailable($bill);

                try {
                    $bill->save();
                } catch (QueryException $exception) {
                    if ($this->isSupplierReferenceUniqueViolation($exception)) {
                        throw DuplicateSupplierReference::forReference($reference);
                    }

                    throw $exception;
                }

                foreach ($lines as $index => $line) {
                    $bill->lines()->create($this->billLineAttributes($line, $index + 1));
                }

                $this->recordStateChange($actor, $bill, 'accounting.bill.created', null, 'draft');

                return $bill->refresh();
            });
        } catch (DuplicateSupplierReference|SupplierReferenceRequired $exception) {
            $this->recordSupplierReferenceRefusal($actor, $attributes, $exception);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    public function recordExpense(User $actor, array $attributes): Expense
    {
        Gate::forUser($actor)->authorize('create', Expense::class);

        return DB::transaction(function () use ($actor, $attributes): Expense {
            $expense = new Expense($attributes);
            $expense->forceFill(['created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()])->save();
            $this->recordStateChange($actor, $expense, 'accounting.expense.created', null, 'draft');

            return $expense->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function recordSupplierPayment(User $actor, array $attributes): SupplierPayment
    {
        Gate::forUser($actor)->authorize('create', SupplierPayment::class);

        return DB::transaction(function () use ($actor, $attributes): SupplierPayment {
            $payment = new SupplierPayment($attributes);
            $payment->forceFill(['created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()])->save();
            $this->recordStateChange($actor, $payment, 'accounting.supplier_payment.created', null, 'draft');

            return $payment->refresh();
        });
    }

    /**
     * Compatibility facade for older Accounting callers.
     * Sales owns invoice lifecycle orchestration and posting.
     */
    public function issueInvoice(User $actor, Invoice $invoice): Invoice
    {
        return app(\App\Services\Sales\InvoiceService::class)->issue($actor, $invoice);
    }

    public function approveBill(User $actor, Bill $bill): Bill
    {
        Gate::forUser($actor)->authorize('approve', $bill);

        return DB::transaction(function () use ($actor, $bill): Bill {
            $billKey = $bill->getKey();

            if (! is_int($billKey)) {
                throw new LogicException('Bill identifiers must be integers.');
            }

            $supplierId = Bill::query()
                ->whereKey($billKey)
                ->value('supplier_id');

            if (! is_numeric($supplierId)) {
                throw new DomainException('A bill requires a supplier.');
            }

            Supplier::query()
                ->whereKey((int) $supplierId)
                ->lockForUpdate()
                ->sole();

            $document = Bill::query()
                ->with('lines.purchaseOrderLine')
                ->whereKey($billKey)
                ->lockForUpdate()
                ->sole();

            $document->forceFill([
                'supplier_reference' => $this->normalizeSupplierReference($document),
            ]);
            $this->assertSupplierReferenceIsAvailable($document);

            if (! $document->isDraft()) {
                throw new DomainException("Bill {$document->bill_number} is no longer a draft.");
            }

            $lines = $document->lines;
            if ($lines->isEmpty()) {
                throw new DomainException("Bill {$document->bill_number} must contain at least one line.");
            }

            [$subtotalMinor, $taxMinor] = $this->assertBillLines($document, $lines);
            $totalMinor = $subtotalMinor + $taxMinor;

            $postingLines = [];
            foreach ($lines as $line) {
                $this->assertPurchaseOrderLineReference($document, $line);
                $accountId = (int) $line->chart_account_id;
                $this->assertPostableAccount($accountId);
                $this->assertNotInventoryAccount($accountId);
                $postingLines[] = [
                    'chart_account_id' => $accountId,
                    'debit' => $this->money($line->line_total),
                    'credit' => '0.00',
                    'description' => (string) $line->description,
                ];
            }

            $postingLines[] = $this->debit('1450', $this->minorMoney($taxMinor), 'Recoverable input tax');
            $postingLines[] = $this->credit('2100', $this->minorMoney($totalMinor), "Payable {$document->bill_number}");

            $entry = $this->journalPosting->postNew(
                $actor,
                CarbonImmutable::parse($document->bill_date),
                $this->nonZeroLines($postingLines),
                "Approved bill {$document->bill_number}",
                $document,
            );

            $document->forceFill([
                'status' => 'approved',
                'grand_total' => $this->minorMoney($totalMinor),
                'total_amount' => $this->minorMoney($totalMinor),
                'journal_entry_id' => $entry->getKey(),
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ])->save();

            $this->recordStateChange($actor, $document, 'accounting.bill.approved', 'draft', 'approved');

            $this->recordTax($document, new TaxRecognition(
                $document->bill_date,
                'input',
                'Recoverable input tax',
                $this->minorMoney($taxMinor),
            ));

            return $document->refresh();
        });
    }

    public function approveExpense(User $actor, Expense $expense): Expense
    {
        Gate::forUser($actor)->authorize('approve', $expense);

        return DB::transaction(function () use ($actor, $expense): Expense {
            $document = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->sole();

            if (! $document->isDraft()) {
                throw new DomainException("Expense {$document->expense_number} is no longer a draft.");
            }

            $accountId = $this->expenseAccountId($document);
            $this->assertPostableAccount($accountId);
            $subtotalMinor = $this->minor($document->subtotal);
            $taxMinor = $this->minor($document->tax_total);
            $totalMinor = $this->minor($document->total_amount);
            $this->assertTotals($subtotalMinor, $taxMinor, $totalMinor, $document->expense_number);

            $entry = $this->journalPosting->postNew(
                $actor,
                CarbonImmutable::parse($document->expense_date),
                $this->nonZeroLines([
                    $this->debit($accountId, $this->minorMoney($subtotalMinor), (string) $document->description),
                    $this->debit('1450', $this->minorMoney($taxMinor), 'Recoverable input tax'),
                    $this->credit('2100', $this->minorMoney($totalMinor), "Payable {$document->expense_number}"),
                ]),
                "Approved expense {$document->expense_number}",
                $document,
            );

            $document->forceFill([
                'status' => 'approved',
                'journal_entry_id' => $entry->getKey(),
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ])->save();

            $this->recordStateChange($actor, $document, 'accounting.expense.approved', 'draft', 'approved');

            $this->recordTax($document, new TaxRecognition(
                $document->expense_date,
                'input',
                'Recoverable input tax',
                $this->minorMoney($taxMinor),
            ));

            return $document->refresh();
        });
    }

    public function payExpense(User $actor, Expense $expense): Expense
    {
        Gate::forUser($actor)->authorize('pay', $expense);

        return DB::transaction(function () use ($actor, $expense): Expense {
            $document = Expense::query()->with('paymentMethod.chartAccount')->whereKey($expense->getKey())->lockForUpdate()->sole();

            if ($document->status !== 'approved') {
                throw new DomainException("Expense {$document->expense_number} must be approved before payment.");
            }

            $paymentMethod = $document->paymentMethod;
            if (! $paymentMethod instanceof PaymentMethod || ! $paymentMethod->is_active) {
                throw new DomainException('An approved expense requires an active payment method before payment.');
            }

            $paymentAccountId = (int) $paymentMethod->chart_account_id;
            $this->assertPostableAccount($paymentAccountId);
            $totalMinor = $this->minor($document->total_amount);

            $this->journalPosting->postNew(
                $actor,
                CarbonImmutable::parse($document->expense_date),
                [
                    $this->debit('2100', $this->minorMoney($totalMinor), "Settle {$document->expense_number}"),
                    $this->credit($paymentAccountId, $this->minorMoney($totalMinor), "Paid {$document->expense_number}"),
                ],
                "Paid expense {$document->expense_number}",
                $document,
            );

            $document->forceFill([
                'status' => 'paid',
                'amount_paid' => $this->minorMoney($totalMinor),
                'paid_at' => now(),
            ])->save();

            $this->recordStateChange($actor, $document, 'accounting.expense.paid', 'approved', 'paid');

            return $document->refresh();
        });
    }

    /** @param list<array<string, mixed>> $allocations */
    public function paySupplierPayment(User $actor, SupplierPayment $payment, array $allocations): SupplierPayment
    {
        Gate::forUser($actor)->authorize('pay', $payment);

        return DB::transaction(function () use ($actor, $payment, $allocations): SupplierPayment {
            $lockedPayment = SupplierPayment::query()->with('paymentMethod.chartAccount')->whereKey($payment->getKey())->lockForUpdate()->sole();

            if (! $lockedPayment->isDraft()) {
                throw new DomainException("Supplier payment {$lockedPayment->supplier_payment_number} is no longer a draft.");
            }

            if ($allocations === []) {
                throw new DomainException('A supplier payment must allocate to at least one bill.');
            }

            $normalized = $this->normalizeAllocations($allocations);

            $paymentMinor = $this->minor($lockedPayment->amount);
            $allocatedMinor = array_sum($normalized);
            if ($allocatedMinor !== $paymentMinor) {
                throw new DomainException("Supplier payment allocations total {$this->minorMoney($allocatedMinor)}, but payment amount is {$this->minorMoney($paymentMinor)}.");
            }

            $bills = Bill::query()
                ->whereIn('id', array_keys($normalized))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($bills->count() !== count($normalized)) {
                throw new DomainException('Every supplier payment allocation must target an existing bill.');
            }

            foreach ($normalized as $billId => $amountMinor) {
                $bill = $bills->get($billId);
                if (! $bill instanceof Bill || ! $bill->isOpen()) {
                    throw new DomainException("Bill {$billId} is not open for payment.");
                }

                if ((int) $bill->supplier_id !== (int) $lockedPayment->supplier_id) {
                    throw new DomainException("Bill {$billId} belongs to a different supplier.");
                }

                $remainingMinor = $this->minor($bill->grandTotal()) - $this->minor($bill->paidAmount());
                if ($amountMinor > $remainingMinor) {
                    throw new DomainException("Allocation {$this->minorMoney($amountMinor)} exceeds bill {$bill->bill_number}'s remaining balance of {$this->minorMoney($remainingMinor)}.");
                }
            }

            $paymentMethod = $lockedPayment->paymentMethod;
            if (! $paymentMethod instanceof PaymentMethod || ! $paymentMethod->is_active) {
                throw new DomainException('A supplier payment requires an active payment method.');
            }

            $paymentAccountId = (int) $paymentMethod->chart_account_id;
            $this->assertPostableAccount($paymentAccountId);

            $entry = $this->journalPosting->postNew(
                $actor,
                CarbonImmutable::parse($lockedPayment->payment_date),
                [
                    $this->debit('2100', $this->minorMoney($paymentMinor), "Settle supplier {$lockedPayment->supplier_id}"),
                    $this->credit($paymentAccountId, $this->minorMoney($paymentMinor), "Supplier payment {$lockedPayment->supplier_payment_number}"),
                ],
                "Supplier payment {$lockedPayment->supplier_payment_number}",
                $lockedPayment,
            );

            foreach ($normalized as $billId => $amountMinor) {
                /** @var Bill $bill */
                $bill = $bills->get($billId);
                $oldStatus = $bill->getRawOriginal('status');
                $newPaidMinor = $this->minor($bill->paidAmount()) + $amountMinor;
                $newStatus = $newPaidMinor === $this->minor($bill->grandTotal()) ? 'paid' : 'partially_paid';

                $lockedPayment->allocations()->create([
                    'bill_id' => $billId,
                    'amount' => $this->minorMoney($amountMinor),
                ]);

                $bill->forceFill([
                    'paid_amount' => $this->minorMoney($newPaidMinor),
                    'amount_paid' => $this->minorMoney($newPaidMinor),
                    'status' => $newStatus,
                    'paid_at' => $newStatus === 'paid' ? now() : $bill->paid_at,
                ])->save();

                $this->recordStateChange(
                    $actor,
                    $bill,
                    'accounting.bill.payment_allocated',
                    is_string($oldStatus) ? $oldStatus : null,
                    $newStatus,
                );
            }

            $lockedPayment->forceFill([
                'status' => 'paid',
                'journal_entry_id' => $entry->getKey(),
            ])->save();

            $this->recordStateChange($actor, $lockedPayment, 'accounting.supplier_payment.paid', 'draft', 'paid');

            return $lockedPayment->refresh();
        });
    }

    public function cancelBill(User $actor, Bill $bill): Bill
    {
        Gate::forUser($actor)->authorize('update', $bill);

        return DB::transaction(function () use ($actor, $bill): Bill {
            $locked = Bill::query()->whereKey($bill->getKey())->lockForUpdate()->sole();
            if (! $locked->isDraft()) {
                throw new DomainException('Only a draft bill can be cancelled.');
            }

            $locked->forceFill(['status' => 'cancelled'])->save();
            $this->recordStateChange($actor, $locked, 'accounting.bill.cancelled', 'draft', 'cancelled');

            return $locked->refresh();
        });
    }

    public function cancelExpense(User $actor, Expense $expense): Expense
    {
        Gate::forUser($actor)->authorize('update', $expense);

        return DB::transaction(function () use ($actor, $expense): Expense {
            $locked = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->sole();
            if (! $locked->isDraft()) {
                throw new DomainException('Only a draft expense can be cancelled.');
            }

            $locked->forceFill(['status' => 'cancelled'])->save();
            $this->recordStateChange($actor, $locked, 'accounting.expense.cancelled', 'draft', 'cancelled');

            return $locked->refresh();
        });
    }

    public function cancelSupplierPayment(User $actor, SupplierPayment $payment): SupplierPayment
    {
        Gate::forUser($actor)->authorize('update', $payment);

        return DB::transaction(function () use ($actor, $payment): SupplierPayment {
            $locked = SupplierPayment::query()->whereKey($payment->getKey())->lockForUpdate()->sole();
            if (! $locked->isDraft()) {
                throw new DomainException('Only a draft supplier payment can be cancelled.');
            }

            $locked->forceFill(['status' => 'cancelled'])->save();
            $this->recordStateChange($actor, $locked, 'accounting.supplier_payment.cancelled', 'draft', 'cancelled');

            return $locked->refresh();
        });
    }

    /**
     * Compatibility facade. Approval reserves customer credit only; the ledger
     * movement is deliberately deferred until RefundService::pay().
     */
    public function approveRefund(User $actor, Refund $refund): Refund
    {
        return app(RefundService::class)->approve($actor, $refund);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function billLineAttributes(array $line, int $sortOrder): array
    {
        $quantityValue = $line['quantity'] ?? 1;
        $unitPriceValue = $line['unit_price'] ?? 0;
        $quantity = is_numeric($quantityValue) ? (float) $quantityValue : 1.0;
        $unitPrice = is_numeric($unitPriceValue) ? (float) $unitPriceValue : 0.0;

        return [
            'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
            'product_variant_id' => $line['product_variant_id'] ?? null,
            'chart_account_id' => $line['chart_account_id'] ?? null,
            'description' => $line['description'] ?? 'Supplier bill line',
            'quantity' => $line['quantity'] ?? 1,
            'unit_price' => $line['unit_price'] ?? 0,
            'tax_amount' => $line['tax_amount'] ?? 0,
            'line_total' => $line['line_total'] ?? number_format(round($quantity * $unitPrice, 2), 2, '.', ''),
            'sort_order' => $line['sort_order'] ?? $sortOrder,
        ];
    }

    /**
     * @param  Collection<int, BillLine>  $lines
     * @return array{0: int, 1: int}
     */
    private function assertBillLines(Bill $bill, Collection $lines): array
    {
        $subtotalMinor = 0;
        $taxMinor = 0;

        foreach ($lines as $line) {
            $this->assertPurchaseOrderLineReference($bill, $line);
            $expectedLineMinor = (int) round((float) $line->quantity * (float) $line->unit_price * 100);
            $actualLineMinor = $this->minor($line->line_total);
            if ($actualLineMinor !== $expectedLineMinor) {
                throw new DomainException("Bill {$bill->bill_number} line {$line->id} has net {$this->minorMoney($actualLineMinor)}, expected {$this->minorMoney($expectedLineMinor)}.");
            }

            if ($actualLineMinor <= 0 || $this->minor($line->tax_amount) < 0) {
                throw new DomainException("Bill {$bill->bill_number} contains an invalid line amount.");
            }

            $subtotalMinor += $actualLineMinor;
            $taxMinor += $this->minor($line->tax_amount);
            $this->assertPostableAccount((int) $line->chart_account_id);
            $this->assertNotInventoryAccount((int) $line->chart_account_id);
        }

        $this->assertTotals($subtotalMinor, $taxMinor, $this->minor($bill->grandTotal()), $bill->bill_number);

        if ($this->minor($bill->subtotal) !== $subtotalMinor || $this->minor($bill->tax_total) !== $taxMinor) {
            throw new DomainException("Bill {$bill->bill_number} states subtotal {$bill->subtotal} and tax {$bill->tax_total}, but lines total {$this->minorMoney($subtotalMinor)} and {$this->minorMoney($taxMinor)}.");
        }

        return [$subtotalMinor, $taxMinor];
    }

    private function assertPurchaseOrderLineReference(Bill $bill, BillLine $line): void
    {
        if (! is_numeric($line->purchase_order_line_id)) {
            return;
        }

        $purchaseOrderLine = $line->purchaseOrderLine;
        if (! $purchaseOrderLine instanceof PurchaseOrderLine
            || ! is_numeric($bill->purchase_order_id)
            || (int) $purchaseOrderLine->purchase_order_id !== (int) $bill->purchase_order_id) {
            throw new DomainException("Bill {$bill->bill_number} references a purchase-order line outside its purchase order.");
        }
    }

    private function assertSupplierReferenceIsAvailable(Bill $bill): void
    {
        $reference = $this->normalizeSupplierReference($bill);

        $duplicate = Bill::withTrashed()
            ->where('supplier_id', $bill->supplier_id)
            ->where('supplier_reference', $reference)
            ->when($bill->exists, fn (Builder $query): Builder => $query->whereKeyNot($bill->getKey()))
            ->exists();

        if ($duplicate) {
            throw DuplicateSupplierReference::forReference($reference);
        }
    }

    private function lockSupplierForBill(Bill $bill): Supplier
    {
        $supplierId = $bill->supplier_id;

        if (! is_numeric($supplierId)) {
            throw new DomainException('A bill requires a supplier.');
        }

        return Supplier::query()
            ->whereKey((int) $supplierId)
            ->lockForUpdate()
            ->sole();
    }

    private function normalizeSupplierReference(Bill $bill): string
    {
        $value = $bill->supplier_reference;
        $reference = is_string($value) ? trim($value) : '';

        if ($reference === '') {
            throw SupplierReferenceRequired::make();
        }

        if (mb_strlen($reference) > 100) {
            throw new DomainException('A supplier invoice reference may not exceed 100 characters.');
        }

        return $reference;
    }

    private function isSupplierReferenceUniqueViolation(QueryException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'bills_supplier_reference_unique')
            || (
                str_contains($message, 'unique')
                && str_contains($message, 'bills.supplier_id')
                && str_contains($message, 'bills.supplier_reference')
            );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function recordSupplierReferenceRefusal(
        User $actor,
        array $attributes,
        DuplicateSupplierReference|SupplierReferenceRequired $exception,
    ): void {
        $reference = $attributes['supplier_reference'] ?? null;
        $supplierId = $attributes['supplier_id'] ?? null;

        activity()
            ->causedBy($actor)
            ->withProperties([
                'source_channel' => 'dashboard',
                'ip_address' => request()->ip(),
                'supplier_id' => is_numeric($supplierId) ? (int) $supplierId : null,
                'supplier_reference' => is_string($reference) && trim($reference) !== ''
                    ? trim($reference)
                    : null,
                'rejection_type' => $exception instanceof DuplicateSupplierReference
                    ? 'duplicate'
                    : 'required',
                'message' => $exception->getMessage(),
            ])
            ->log('accounting.bill.supplier_reference_rejected');
    }


    /**
     * @param  list<array<string, mixed>>  $allocations
     * @return array<int, int>
     */
    private function normalizeAllocations(array $allocations): array
    {
        $normalized = [];

        foreach ($allocations as $allocation) {
            $billValue = $allocation['bill_id'] ?? null;
            $amountValue = $allocation['amount'] ?? null;
            if (! is_numeric($billValue) || ! is_numeric($amountValue)) {
                throw new DomainException('Every supplier payment allocation must have a positive bill and amount.');
            }

            $billId = (int) $billValue;
            $amountMinor = $this->minor($amountValue);
            if ($billId <= 0 || $amountMinor <= 0) {
                throw new DomainException('Every supplier payment allocation must have a positive bill and amount.');
            }

            $normalized[$billId] = ($normalized[$billId] ?? 0) + $amountMinor;
        }

        return $normalized;
    }

    private function expenseAccountId(Expense $expense): int
    {
        $accountId = $expense->chart_account_id ?? $expense->expense_account_id;
        if (! is_numeric($accountId) || (int) $accountId <= 0) {
            throw new DomainException('An expense requires a chart account.');
        }

        return (int) $accountId;
    }

    private function assertPostableAccount(int $accountId): void
    {
        $isUsable = ChartAccount::query()
            ->whereKey($accountId)
            ->where('is_postable', true)
            ->where('is_active', true)
            ->exists();

        if (! $isUsable) {
            throw new DomainException("Chart account {$accountId} must be active and postable.");
        }
    }

    private function assertNotInventoryAccount(int $accountId): void
    {
        $account = ChartAccount::query()->whereKey($accountId)->first();
        if ($account instanceof ChartAccount && (string) $account->code === '1300') {
            throw new DomainException('Supplier bills cannot post to the Inventory account.');
        }
    }

    private function recordStateChange(User $actor, Model $subject, string $event, ?string $from, string $to): void
    {
        activity()
            ->performedOn($subject)
            ->causedBy($actor)
            ->withChanges([
                'old' => ['status' => $from],
                'attributes' => ['status' => $to],
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log($event);
    }

    private function assertTotalMatchesComponents(int|float|string $subtotal, int|float|string $taxTotal, int|float|string $totalAmount, string $number): void
    {
        $this->assertTotals($this->minor($subtotal), $this->minor($taxTotal), $this->minor($totalAmount), $number);
    }

    private function assertTotals(int $subtotalMinor, int $taxMinor, int $totalMinor, string $number): void
    {
        if ($subtotalMinor < 0 || $taxMinor < 0 || $totalMinor <= 0 || $subtotalMinor + $taxMinor !== $totalMinor) {
            throw new DomainException("Document {$number} must have a positive total equal to its subtotal plus tax.");
        }
    }

    private function recordTax(Model $source, TaxRecognition $recognition): void
    {
        if ($this->minor($recognition->amount) <= 0) {
            return;
        }

        TaxRecognitionEntry::query()->updateOrCreate(
            [
                'source_type' => $source::class,
                'source_id' => $source->getKey(),
                'direction' => $recognition->direction,
            ],
            [
                'tax_date' => CarbonImmutable::parse($recognition->date)->toDateString(),
                'tax_type' => $recognition->type,
                'tax_amount' => $this->money($recognition->amount),
            ],
        );
    }

    /** @return array{chart_account_id: int, debit: string, credit: string, description: string} */
    private function debit(string|int $accountCode, int|float|string $amount, string $description): array
    {
        $accountId = is_int($accountCode) ? $accountCode : $this->accountId($accountCode);

        return [
            'chart_account_id' => $accountId,
            'debit' => $this->money($amount),
            'credit' => '0.00',
            'description' => $description,
        ];
    }

    /** @return array{chart_account_id: int, debit: string, credit: string, description: string} */
    private function credit(string|int $accountCode, int|float|string $amount, string $description): array
    {
        $accountId = is_int($accountCode) ? $accountCode : $this->accountId($accountCode);

        return [
            'chart_account_id' => $accountId,
            'debit' => '0.00',
            'credit' => $this->money($amount),
            'description' => $description,
        ];
    }

    private function accountId(string $code): int
    {
        $id = ChartAccount::query()
            ->where('code', $code)
            ->where('is_postable', true)
            ->where('is_active', true)
            ->value('id');

        if (! is_numeric($id)) {
            throw new LogicException("The accounting chart must contain postable account {$code}.");
        }

        return (int) $id;
    }

    /**
     * @param  list<array{chart_account_id: int, debit: string, credit: string, description: string}>  $lines
     * @return list<array{chart_account_id: int, debit: string, credit: string, description: string}>
     */
    private function nonZeroLines(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            fn (array $line): bool => $this->minor($line['debit']) > 0 || $this->minor($line['credit']) > 0,
        ));
    }

    private function minor(int|float|string $amount): int
    {
        return JournalEntryLine::toMinorUnits($amount);
    }

    private function money(int|float|string $amount): string
    {
        return number_format($this->minor($amount) / 100, 2, '.', '');
    }

    private function minorMoney(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }
}
