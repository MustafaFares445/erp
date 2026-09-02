<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\SalesSetting;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Services\Sales\SalesAccountResolver;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class RefundService
{
    public function __construct(
        private SalesAccountResolver $accounts,
        private JournalPostingService $journalPosting,
    ) {}

    public function availableCreditMinor(int $customerId, ?int $excludingRefundId = null): int
    {
        $invoiceClaimMinor = Invoice::query()
            ->where('customer_id', $customerId)
            ->whereNotNull('issued_at')
            ->whereNotIn('status', ['cancelled'])
            ->get(['total_amount', 'credited_amount'])
            ->sum(fn (Invoice $invoice): int => max(
                0,
                $this->minor($invoice->total_amount) - $this->minor($invoice->credited_amount),
            ));

        $collectionsMinor = Payment::query()
            ->where('customer_id', $customerId)
            ->where('status', 'posted')
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
            ->whereIn('status', ['approved', 'paid']);

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
            $locked = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->sole();

            if (! $locked->isDraft()) {
                throw new DomainException('Only a draft refund can be approved.');
            }

            if ((int) $locked->created_by === (int) $actor->getKey()) {
                throw new DomainException('The user who recorded a refund cannot approve it.');
            }

            CustomerProfile::query()->whereKey($locked->customer_id)->lockForUpdate()->sole();

            $available = $this->availableCreditMinor((int) $locked->customer_id);
            $requested = $this->minor($locked->amount);

            if ($requested > $available) {
                throw new DomainException(sprintf(
                    'Refund %.2f exceeds available customer credit %.2f.',
                    $requested / 100,
                    $available / 100,
                ));
            }

            $locked->forceFill([
                'status' => 'approved',
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges(['attributes' => ['status' => 'approved']])
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

            if (! $locked->isApproved()) {
                throw new DomainException('Only an approved refund can be paid.');
            }

            CustomerProfile::query()->whereKey($locked->customer_id)->lockForUpdate()->sole();

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
                'status' => 'paid',
                'journal_entry_id' => $journal->getKey(),
                'paid_by' => $actor->getKey(),
                'paid_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges(['attributes' => ['status' => 'paid']])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('accounting.refund.paid');

            return $locked->refresh();
        }, attempts: 5);
    }

    private function unrecogniseTaxWhenRequired(User $actor, Refund $refund, SalesSetting $settings): void
    {
        // An invoice-linked credit note already reverses the recognised/deferred
        // tax split when it is confirmed. Paying that credit out must not reverse
        // the same tax a second time.
        if ($refund->credit_note_id !== null || ! $refund->invoice instanceof Invoice) {
            return;
        }

        $invoice = Invoice::query()->whereKey($refund->invoice_id)->lockForUpdate()->sole();
        $recognised = (float) $invoice->recognised_tax_amount;

        if ($recognised <= 0.0 || (float) $invoice->total_amount <= 0.0) {
            return;
        }

        $tax = min(
            $recognised,
            round(((float) $refund->amount / (float) $invoice->total_amount) * (float) $invoice->tax_total, 2),
        );

        if ($tax <= 0.0) {
            return;
        }

        $entry = TaxRecognitionEntry::query()->create([
            'tax_date' => $refund->refund_date,
            'direction' => 'refund',
            'tax_type' => 'sales_tax',
            'tax_amount' => -$tax,
            'source_type' => Refund::class,
            'source_id' => $refund->getKey(),
            'invoice_id' => $invoice->getKey(),
            'refund_id' => $refund->getKey(),
            'payment_id' => null,
            'payment_amount' => -1 * (float) $refund->amount,
            'recognised_tax_amount' => -$tax,
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
                    'debit' => number_format($tax, 2, '.', ''),
                    'credit' => '0.00',
                    'description' => 'Refund tax un-recognition',
                ],
                [
                    'chart_account_id' => (int) $deferred->getKey(),
                    'debit' => '0.00',
                    'credit' => number_format($tax, 2, '.', ''),
                    'description' => 'Return refunded tax to deferred balance',
                ],
            ],
            "Refund tax correction {$refund->refund_number}",
            $entry,
        );

        $entry->forceFill(['journal_entry_id' => $journal->getKey()])->save();
        $invoice->forceFill([
            'recognised_tax_amount' => max(0.0, round($recognised - $tax, 2)),
        ])->save();
    }

    private function minor(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
