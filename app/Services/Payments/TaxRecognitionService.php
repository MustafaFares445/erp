<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesSetting;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Sales\SalesAccountResolver;
use Carbon\CarbonImmutable;

final readonly class TaxRecognitionService
{
    public function __construct(
        private SalesAccountResolver $accounts,
        private JournalPostingService $journalPosting,
    ) {}

    public function recognise(User $actor, Payment $payment, PaymentAllocation $allocation): ?TaxRecognitionEntry
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->whereKey($allocation->invoice_id)->lockForUpdate()->sole();
        $taxTotal = (float) $invoice->tax_total;

        if ($taxTotal <= 0.0) {
            return null;
        }

        $claim = max(0.0, (float) $invoice->total_amount - (float) $invoice->credited_amount);
        $settling = (float) $invoice->amount_paid + 0.00001 >= $claim;
        $remaining = max(0.0, round($taxTotal - (float) $invoice->recognised_tax_amount, 2));

        $recognised = $settling
            ? $remaining
            : min($remaining, round(((float) $allocation->amount / (float) $invoice->total_amount) * $taxTotal, 2));

        if ($recognised <= 0.0) {
            return null;
        }

        $entry = TaxRecognitionEntry::query()->create([
            'tax_date' => $payment->payment_date,
            'direction' => 'output',
            'tax_type' => 'sales_tax',
            'tax_amount' => $recognised,
            'source_type' => Payment::class,
            'source_id' => $payment->getKey(),
            'invoice_id' => $invoice->getKey(),
            'payment_id' => $payment->getKey(),
            'payment_amount' => $allocation->amount,
            'recognised_tax_amount' => $recognised,
            'recognition_date' => $payment->payment_date,
        ]);

        $settings = SalesSetting::current()->load(['deferredTaxAccount', 'taxPayableAccount']);
        $deferred = $this->accounts->deferredTax($settings);
        $payable = $this->accounts->taxPayable($settings);

        $journal = $this->journalPosting->postNew(
            $actor,
            CarbonImmutable::parse($payment->payment_date),
            [
                [
                    'chart_account_id' => (int) $deferred->getKey(),
                    'debit' => number_format($recognised, 2, '.', ''),
                    'credit' => '0.00',
                    'description' => "Recognise tax for {$invoice->invoice_number}",
                ],
                [
                    'chart_account_id' => (int) $payable->getKey(),
                    'debit' => '0.00',
                    'credit' => number_format($recognised, 2, '.', ''),
                    'description' => "Sales tax payable {$invoice->invoice_number}",
                ],
            ],
            "Tax recognition {$invoice->invoice_number}",
            $entry,
        );

        $entry->forceFill(['journal_entry_id' => $journal->getKey()])->save();

        $invoice->forceFill([
            'recognised_tax_amount' => round((float) $invoice->recognised_tax_amount + $recognised, 2),
        ])->save();

        return $entry->refresh();
    }

    public function reverseForPayment(User $actor, Payment $payment): void
    {
        $entries = TaxRecognitionEntry::query()
            ->where('payment_id', $payment->getKey())
            ->where('recognised_tax_amount', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($entries as $entry) {
            if ($entry->journalEntry) {
                $reversal = $this->journalPosting->reverse(
                    $actor,
                    $entry->journalEntry,
                    CarbonImmutable::today(),
                    "Reverse tax recognition for {$payment->payment_number}",
                );
            } else {
                $reversal = null;
            }

            if ($entry->invoice_id !== null) {
                /** @var Invoice $invoice */
                $invoice = Invoice::query()->whereKey($entry->invoice_id)->lockForUpdate()->sole();
                $invoice->forceFill([
                    'recognised_tax_amount' => max(
                        0.0,
                        round((float) $invoice->recognised_tax_amount - (float) $entry->recognised_tax_amount, 2),
                    ),
                ])->save();
            }

            TaxRecognitionEntry::query()->create([
                'tax_date' => now()->toDateString(),
                'direction' => 'output_reversal',
                'tax_type' => 'sales_tax',
                'tax_amount' => -1 * (float) $entry->tax_amount,
                'source_type' => Payment::class,
                'source_id' => $payment->getKey(),
                'invoice_id' => $entry->invoice_id,
                'payment_id' => null,
                'journal_entry_id' => $reversal?->getKey(),
                'payment_amount' => -1 * (float) ($entry->payment_amount ?? 0),
                'recognised_tax_amount' => -1 * (float) $entry->recognised_tax_amount,
                'recognition_date' => now()->toDateString(),
            ]);
        }
    }
}
