<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Events\CustomerDepositApplied;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Sales\SalesAccountResolver;
use App\Services\Settings\CurrencyCatalogService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies existing unallocated posted Customer Deposits to an issued
 * invoice's outstanding balance — the missing half of the Customer App V1
 * payment/invoice architecture: {@see PaymentPostingService} already credits
 * Customer Deposits for an unallocated remainder; this is what consumes that
 * deposit later, once an invoice exists to consume it against.
 *
 * Deposits are selected oldest-first, deterministically, and capped so a
 * call never allocates more than the invoice's outstanding balance or a
 * payment's own unallocated remainder. Every write is a *new*
 * {@see PaymentAllocation} row via the existing
 * {@see PaymentAllocationService} — posted allocations are immutable and
 * this service never touches one. Re-running against an invoice that is
 * already fully settled, or a payment that already has an allocation for
 * this invoice, is a no-op, which is what makes the whole operation
 * idempotent and safe to retry.
 */
final readonly class CustomerDepositApplicationService
{
    public function __construct(
        private SystemActorResolver $systemActor,
        private PaymentAllocationService $allocations,
        private TaxRecognitionService $taxRecognition,
        private JournalPostingService $journalPosting,
        private SalesAccountResolver $accounts,
        private CurrencyCatalogService $currencies,
    ) {}

    public function applyEligibleDeposits(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->sole();

            if (! $locked->isIssued() || $locked->outstandingAmount() <= 0.0) {
                return $locked;
            }

            $actor = $this->systemActor->resolve();
            $defaultCurrency = $this->currencies->defaultCode();

            CustomerProfile::query()
                ->whereKey($locked->customer_id)
                ->lockForUpdate()
                ->sole();

            $deposits = Payment::query()
                ->where('customer_id', $locked->customer_id)
                ->where('status', PaymentStatus::Posted->value)
                ->where('currency', $defaultCurrency)
                ->orderBy('payment_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $reservedRefundMinor = Refund::query()
                ->where('customer_id', $locked->customer_id)
                ->whereIn('status', [RefundStatus::Approved->value, RefundStatus::Paid->value])
                ->sum('customer_deposit_amount');
            $reservedRefundMinor = is_numeric($reservedRefundMinor)
                ? JournalEntryLine::toMinorUnits($reservedRefundMinor)
                : 0;

            foreach ($deposits as $payment) {
                if ($locked->refresh()->outstandingAmount() <= 0.0) {
                    break;
                }

                $depositMinor = $payment->customerDepositMinor();
                $reservedAgainstPayment = min($depositMinor, $reservedRefundMinor);
                $reservedRefundMinor -= $reservedAgainstPayment;
                $availableMinor = $depositMinor - $reservedAgainstPayment;

                if ($availableMinor <= 0) {
                    continue;
                }

                $this->applyOneDeposit($actor, $payment, $locked, $availableMinor / 100);
            }

            return $locked->refresh();
        });
    }

    private function applyOneDeposit(
        User $actor,
        Payment $payment,
        Invoice $invoice,
        ?float $availableCap = null,
    ): void {
        if ($payment->allocations()->where('invoice_id', $invoice->getKey())->exists()) {
            return;
        }

        $allocatedSoFar = (float) $payment->allocations()->sum('amount');
        $unallocated = round((float) $payment->amount - $allocatedSoFar, 2);

        if ($unallocated <= 0.0) {
            return;
        }

        $amountToApply = min(
            $unallocated,
            $invoice->outstandingAmount(),
            $availableCap ?? $unallocated,
        );

        if ($amountToApply <= 0.0) {
            return;
        }

        $invoiceId = $invoice->getKey();
        $allocation = $this->allocations->allocate($payment, is_numeric($invoiceId) ? (int) $invoiceId : 0, $amountToApply);
        $applicationDate = CarbonImmutable::today();

        $this->postDepositTransfer($actor, $payment, $invoice, $allocation, $amountToApply, $applicationDate);

        $this->taxRecognition->recognise($actor, $payment, $allocation, $applicationDate);

        CustomerDepositApplied::dispatch($invoice, $allocation);
    }

    public function reverseForPayment(User $actor, Payment $payment): void
    {
        $allocations = PaymentAllocation::query()
            ->with('invoice')
            ->where('payment_id', $payment->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $allocation) {
            $entry = JournalEntry::query()
                ->where('source_type', PaymentAllocation::class)
                ->where('source_id', $allocation->getKey())
                ->where('status', 'posted')
                ->first();

            // Compatibility for deposit applications posted before allocations
            // became the explicit journal source.
            if (! $entry instanceof JournalEntry && $allocation->invoice instanceof Invoice) {
                $entry = JournalEntry::query()
                    ->where('source_type', Invoice::class)
                    ->where('source_id', $allocation->invoice_id)
                    ->where('status', 'posted')
                    ->where(
                        'description',
                        "Customer deposit applied: {$payment->payment_number} -> {$allocation->invoice->invoice_number}",
                    )
                    ->first();
            }

            if (! $entry instanceof JournalEntry || $entry->reversal()->exists()) {
                continue;
            }

            $this->journalPosting->reverse(
                $actor,
                $entry,
                CarbonImmutable::today(),
                "Reverse customer deposit application for {$payment->payment_number}",
            );
        }
    }

    private function postDepositTransfer(
        User $actor,
        Payment $payment,
        Invoice $invoice,
        PaymentAllocation $allocation,
        float $amount,
        CarbonImmutable $applicationDate,
    ): void {
        $settings = SalesSetting::current()->load(['receivableAccount', 'customerDepositsAccount']);
        $deposits = $this->accounts->customerDeposits($settings);
        $receivable = $this->accounts->receivable($settings);
        $formatted = number_format($amount, 2, '.', '');

        $this->journalPosting->postNew(
            $actor,
            $applicationDate,
            [
                [
                    'chart_account_id' => $deposits->id,
                    'debit' => $formatted,
                    'credit' => '0.00',
                    'description' => "Apply deposit from payment {$payment->payment_number} to invoice {$invoice->invoice_number}",
                ],
                [
                    'chart_account_id' => $receivable->id,
                    'debit' => '0.00',
                    'credit' => $formatted,
                    'description' => "Settle invoice {$invoice->invoice_number} from customer deposit",
                ],
            ],
            "Customer deposit applied: {$payment->payment_number} -> {$invoice->invoice_number}",
            $allocation,
        );
    }
}
