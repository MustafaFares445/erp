<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesSetting;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Sales\SalesAccountResolver;
use App\Support\ProportionalAllocator;
use Carbon\CarbonImmutable;

final readonly class TaxRecognitionService
{
    public function __construct(
        private SalesAccountResolver $accounts,
        private JournalPostingService $journalPosting,
        private ProportionalAllocator $allocator,
    ) {}

    public function recognise(User $actor, Payment $payment, PaymentAllocation $allocation): ?TaxRecognitionEntry
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->whereKey($allocation->invoice_id)->lockForUpdate()->sole();

        $taxTotalMinor = JournalEntryLine::toMinorUnits($invoice->tax_total);
        if ($taxTotalMinor <= 0) {
            return null;
        }

        $totalMinor = JournalEntryLine::toMinorUnits($invoice->total_amount);
        $creditedMinor = JournalEntryLine::toMinorUnits($invoice->credited_amount);
        $claimMinor = max(0, $totalMinor - $creditedMinor);
        $paidMinor = JournalEntryLine::toMinorUnits($invoice->amount_paid);
        $recognisedTaxMinor = JournalEntryLine::toMinorUnits($invoice->recognised_tax_amount);
        $allocationMinor = JournalEntryLine::toMinorUnits($allocation->amount);

        $recognisedMinor = $this->allocator->allocate(
            totalMinor: $taxTotalMinor,
            partMinor: $allocationMinor,
            wholeMinor: max(1, $totalMinor),
            alreadyAllocatedMinor: $recognisedTaxMinor,
            settlesRemainder: $paidMinor >= $claimMinor,
        );

        if ($recognisedMinor <= 0) {
            return null;
        }

        $recognised = self::money($recognisedMinor);

        $entry = TaxRecognitionEntry::query()->create([
            'tax_date' => $payment->payment_date,
            'direction' => 'output',
            'tax_type' => 'sales_tax',
            'tax_amount' => $recognised,
            'source_type' => Payment::class,
            'source_id' => $payment->getKey(),
            'invoice_id' => $invoice->getKey(),
            'payment_id' => $payment->getKey(),
            'payment_amount' => self::money($allocationMinor),
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
                    'debit' => $recognised,
                    'credit' => '0.00',
                    'description' => "Recognise tax for {$invoice->invoice_number}",
                ],
                [
                    'chart_account_id' => (int) $payable->getKey(),
                    'debit' => '0.00',
                    'credit' => $recognised,
                    'description' => "Sales tax payable {$invoice->invoice_number}",
                ],
            ],
            "Tax recognition {$invoice->invoice_number}",
            $entry,
        );

        $entry->forceFill(['journal_entry_id' => $journal->getKey()])->save();

        $invoice->forceFill([
            'recognised_tax_amount' => self::money($recognisedTaxMinor + $recognisedMinor),
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

    private static function money(int $minor): string
    {
        $absolute = abs($minor);
        $value = sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);

        return $minor < 0 ? '-'.$value : $value;
    }
}
