<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\SalesSetting;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Services\Concerns\EnforcesMakerChecker;
use App\Services\Sales\SalesAccountResolver;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class RefundService
{
    use EnforcesMakerChecker;

    public function __construct(
        private SalesAccountResolver $accounts,
        private JournalPostingService $journalPosting,
    ) {}

    public function availableCreditMinor(int $customerId, ?int $excludingRefundId = null): int
    {
        $invoiceClaimMinor = Invoice::query()
            ->where('customer_id', $customerId)
            ->whereNotNull('issued_at')
            ->whereNot('status', InvoiceStatus::Cancelled->value)
            ->get(['total_amount', 'credited_amount'])
            ->sum(fn (Invoice $invoice): int => max(
                0,
                $this->minor($invoice->total_amount) - $this->minor($invoice->credited_amount),
            ));

        $collectionsMinor = Payment::query()
            ->where('customer_id', $customerId)
            ->where('status', PaymentStatus::Posted->value)
            ->whereNull('reversed_at')
            ->get(['amount'])
            ->sum(fn (Payment $payment): int => $this->minor($payment->amount));

        $standaloneCreditsMinor = CreditNote::query()
            ->where('customer_id', $customerId)
            ->whereNull('invoice_id')
            ->where('status', 'confirmed')
            ->whereNull('reversed_at')
            ->get(['grand_total'])
            ->sum(fn (CreditNote $credit): int => $this->minor($credit->grand_total));

        $refundQuery = Refund::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', [RefundStatus::Approved->value, RefundStatus::Paid->value]);

        if ($excludingRefundId !== null) {
            $refundQuery->whereKeyNot($excludingRefundId);
        }

        $reservedRefundMinor = $refundQuery->get(['amount'])
            ->sum(fn (Refund $refund): int => $this->minor($refund->amount));

        return max(
            0,
            $collectionsMinor - $invoiceClaimMinor + $standaloneCreditsMinor - $reservedRefundMinor,
        );
    }

    public function approve(User $actor, Refund $refund): Refund
    {
        Gate::forUser($actor)->authorize('approve', $refund);

        return DB::transaction(function () use ($actor, $refund): Refund {
            /** @var Refund $locked */
            $locked = Refund::query()
                ->with(['invoice', 'creditNote'])
                ->whereKey($refund->getKey())
                ->lockForUpdate()
                ->sole();

            $locked->assertCanTransitionTo(RefundStatus::Approved);

            $this->assertDifferentActor(
                is_numeric($locked->created_by) ? (int) $locked->created_by : null,
                $actor,
                'The user who recorded a refund cannot approve it.',
            );

            CustomerProfile::query()->whereKey($locked->customer_id)->lockForUpdate()->sole();
            $this->assertSourceMatchesCustomer($locked);

            $available = $this->availableCreditMinor((int) $locked->customer_id, (int) $locked->getKey());
            $requested = $this->minor($locked->amount);

            if ($requested > $available) {
                throw new DomainException(sprintf(
                    'Refund %.2f exceeds available customer credit %.2f.',
                    $requested / 100,
                    $available / 100,
                ));
            }

            $locked->forceFill([
                'status' => RefundStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges(['attributes' => ['status' => RefundStatus::Approved->value]])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('accounting.refund.approved');

            return $locked->refresh();
        }, attempts: 5);
    }

    public function pay(User $actor, Refund $refund): Refund
    {
        Gate::forUser($actor)->authorize('pay', $refund);

        return DB::transaction(function () use ($actor, $refund): Refund {
            /** @var Refund $locked */
            $locked = Refund::query()
                ->with(['paymentMethod.chartAccount', 'invoice', 'creditNote'])
                ->whereKey($refund->getKey())
                ->lockForUpdate()
                ->sole();

            $locked->assertCanTransitionTo(RefundStatus::Paid);

            CustomerProfile::query()->whereKey($locked->customer_id)->lockForUpdate()->sole();
            $this->assertSourceMatchesCustomer($locked);

            $method = $locked->paymentMethod;
            if (! $method instanceof PaymentMethod || ! $method->is_active || ! $method->chartAccount) {
                throw new DomainException('A paid refund requires an active payment method with a posting account.');
            }

            $settings = SalesSetting::current()->load([
                'receivableAccount', 'deferredTaxAccount', 'taxPayableAccount',
            ]);
            $receivable = $this->accounts->receivable($settings);
            $collection = $this->accounts->collectionFor($method->chartAccount);

            $journal = $this->journalPosting->postNew(
                $actor,
                CarbonImmutable::parse($locked->refund_date),
                [
                    [
                        'chart_account_id' => (int) $receivable->getKey(),
                        'debit' => (string) $locked->amount,
                        'credit' => '0.00',
                        'description' => "Refund {$locked->refund_number}",
                    ],
                    [
                        'chart_account_id' => (int) $collection->getKey(),
                        'debit' => '0.00',
                        'credit' => (string) $locked->amount,
                        'description' => "Customer cash refund {$locked->refund_number}",
                    ],
                ],
                "Paid customer refund {$locked->refund_number}",
                $locked,
            );

            $this->unrecogniseTaxWhenRequired($actor, $locked, $settings);

            $locked->forceFill([
                'status' => RefundStatus::Paid,
                'journal_entry_id' => $journal->getKey(),
                'paid_by' => $actor->getKey(),
                'paid_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges(['attributes' => ['status' => RefundStatus::Paid->value]])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('accounting.refund.paid');

            return $locked->refresh();
        }, attempts: 5);
    }

    public function cancel(User $actor, Refund $refund): Refund
    {
        Gate::forUser($actor)->authorize('update', $refund);

        return DB::transaction(function () use ($actor, $refund): Refund {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->sole();

            $locked->assertCanTransitionTo(RefundStatus::Cancelled);

            $locked->forceFill([
                'status' => RefundStatus::Cancelled,
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges(['attributes' => ['status' => RefundStatus::Cancelled->value]])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('accounting.refund.cancelled');

            return $locked->refresh();
        });
    }

    private function assertSourceMatchesCustomer(Refund $refund): void
    {
        if ($refund->creditNote instanceof CreditNote) {
            if ((int) $refund->creditNote->customer_id !== (int) $refund->customer_id
                || $refund->creditNote->status !== 'confirmed'
                || $refund->creditNote->isReversed()) {
                throw new DomainException('The refund source credit note must be a confirmed credit for the same customer.');
            }

            if ($refund->invoice_id !== null
                && (int) $refund->creditNote->invoice_id !== (int) $refund->invoice_id) {
                throw new DomainException('The refund invoice must match the source credit note.');
            }
        }

        if ($refund->invoice instanceof Invoice
            && (int) $refund->invoice->customer_id !== (int) $refund->customer_id) {
            throw new DomainException('The refund invoice must belong to the same customer.');
        }
    }

    private function unrecogniseTaxWhenRequired(User $actor, Refund $refund, SalesSetting $settings): void
    {
        if ($refund->credit_note_id !== null || ! $refund->invoice instanceof Invoice) {
            return;
        }

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->whereKey($refund->invoice_id)->lockForUpdate()->sole();
        $sources = TaxRecognitionEntry::query()
            ->where('invoice_id', $invoice->getKey())
            ->whereNotNull('payment_id')
            ->where('recognised_tax_amount', '>', 0)
            ->orderBy('recognition_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'payment_amount', 'recognised_tax_amount']);

        $taxMinor = $this->refundTaxMinor($refund, $invoice, $sources);

        if ($taxMinor <= 0) {
            return;
        }

        $tax = $this->minorMoney($taxMinor);
        $entry = TaxRecognitionEntry::query()->create([
            'tax_date' => $refund->refund_date,
            'direction' => 'refund',
            'tax_type' => 'sales_tax',
            'tax_amount' => '-'.$tax,
            'source_type' => Refund::class,
            'source_id' => $refund->getKey(),
            'invoice_id' => $invoice->getKey(),
            'refund_id' => $refund->getKey(),
            'payment_id' => null,
            'payment_amount' => '-'.$this->minorMoney($this->minor($refund->amount)),
            'recognised_tax_amount' => '-'.$tax,
            'recognition_date' => $refund->refund_date,
        ]);

        $payable = $this->accounts->taxPayable($settings);
        $deferred = $this->accounts->deferredTax($settings);

        $journal = $this->journalPosting->postNew(
            $actor,
            CarbonImmutable::parse($refund->refund_date),
            [
                [
                    'chart_account_id' => (int) $payable->getKey(),
                    'debit' => $tax,
                    'credit' => '0.00',
                    'description' => 'Refund tax un-recognition',
                ],
                [
                    'chart_account_id' => (int) $deferred->getKey(),
                    'debit' => '0.00',
                    'credit' => $tax,
                    'description' => 'Return refunded tax to deferred balance',
                ],
            ],
            "Refund tax correction {$refund->refund_number}",
            $entry,
        );

        $entry->forceFill(['journal_entry_id' => $journal->getKey()])->save();
        $invoice->forceFill([
            'recognised_tax_amount' => max(
                0.0,
                round((float) $invoice->recognised_tax_amount - ($taxMinor / 100), 2),
            ),
        ])->save();
    }

    /**
     * @param  Collection<int, TaxRecognitionEntry>  $sources
     */
    private function refundTaxMinor(Refund $refund, Invoice $invoice, Collection $sources): int
    {
        if ($sources->isEmpty()) {
            return 0;
        }

        $priorRefundMinor = Refund::query()
            ->where('invoice_id', $invoice->getKey())
            ->whereNull('credit_note_id')
            ->where('status', RefundStatus::Paid->value)
            ->whereKeyNot($refund->getKey())
            ->get(['amount'])
            ->sum(fn (Refund $paidRefund): int => $this->minor($paidRefund->amount));

        $currentRemaining = $this->minor($refund->amount);
        $priorRemaining = $priorRefundMinor;
        $taxMinor = 0;

        foreach ($sources as $source) {
            $sourceAmountMinor = max(0, $this->minor($source->payment_amount));
            $sourceTaxMinor = max(0, $this->minor($source->recognised_tax_amount));

            if ($sourceAmountMinor === 0 || $sourceTaxMinor === 0) {
                continue;
            }

            $priorTaken = min($sourceAmountMinor, $priorRemaining);
            $priorRemaining -= $priorTaken;

            $available = $sourceAmountMinor - $priorTaken;
            $currentTaken = min($available, $currentRemaining);

            if ($currentTaken <= 0) {
                continue;
            }

            $beforeTax = $this->proportionalMinor($priorTaken, $sourceAmountMinor, $sourceTaxMinor);
            $afterTax = $this->proportionalMinor(
                $priorTaken + $currentTaken,
                $sourceAmountMinor,
                $sourceTaxMinor,
            );

            $taxMinor += max(0, $afterTax - $beforeTax);
            $currentRemaining -= $currentTaken;

            if ($currentRemaining <= 0) {
                break;
            }
        }

        return $taxMinor;
    }

    private function proportionalMinor(int $part, int $whole, int $taxMinor): int
    {
        if ($part <= 0 || $whole <= 0 || $taxMinor <= 0) {
            return 0;
        }

        if ($part >= $whole) {
            return $taxMinor;
        }

        return (int) round(($part / $whole) * $taxMinor);
    }

    private function minor(mixed $amount): int
    {
        return JournalEntryLine::toMinorUnits(is_int($amount) || is_float($amount) || is_string($amount) ? $amount : 0);
    }

    private function minorMoney(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }
}
