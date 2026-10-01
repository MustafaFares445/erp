<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\SalesSetting;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Support\ProportionalAllocator;
use Carbon\CarbonImmutable;

/**
 * Posts a confirmed credit note and keeps the invoice's tax recognition
 * consistent with it.
 *
 * `invoices.recognised_tax_amount` is the NET tax currently held as payable
 * for the invoice. The deferred tax still held is therefore
 * `tax_total - confirmed credited tax - recognised_tax_amount` and must never
 * go negative. After a credit note the recognised tax is re-targeted to
 * `effective tax x min(1, paid / remaining claim)`: the part of the credit
 * note's tax that the customer had already paid for is taken from payable,
 * the rest from deferred, and any shortfall (the credit raised the paid share
 * of the remaining claim) is moved from deferred to payable by a tax
 * recognition entry so the tax register keeps reconciling.
 */
final readonly class CreditNotePostingService
{
    public function __construct(
        private SalesAccountResolver $accounts,
        private JournalPostingService $journalPosting,
        private ProportionalAllocator $allocator,
    ) {}

    public function post(User $actor, CreditNote $creditNote, ?Invoice $invoice): JournalEntry
    {
        $settings = SalesSetting::current()->load([
            'receivableAccount', 'revenueAccount', 'deferredTaxAccount', 'taxPayableAccount',
        ]);

        $receivable = $this->accounts->receivable($settings);
        $revenue = $this->accounts->revenue($settings);
        $deferred = $this->accounts->deferredTax($settings);
        $payable = $this->accounts->taxPayable($settings);

        $taxMinor = JournalEntryLine::toMinorUnits($creditNote->tax_total);

        [$recognisedPortionMinor, $trueUpMinor] = $invoice instanceof Invoice
            ? $this->splitTax($creditNote, $invoice, $taxMinor)
            : [0, 0];
        $deferredPortionMinor = $taxMinor - $recognisedPortionMinor;

        $lines = [
            [
                'chart_account_id' => $revenue->id,
                'debit' => (string) $creditNote->subtotal,
                'credit' => '0.00',
                'description' => "Revenue correction {$creditNote->credit_note_number}",
            ],
        ];

        if ($deferredPortionMinor > 0) {
            $lines[] = [
                'chart_account_id' => $deferred->id,
                'debit' => self::money($deferredPortionMinor),
                'credit' => '0.00',
                'description' => 'Deferred tax correction',
            ];
        }

        if ($recognisedPortionMinor > 0) {
            $lines[] = [
                'chart_account_id' => $payable->id,
                'debit' => self::money($recognisedPortionMinor),
                'credit' => '0.00',
                'description' => 'Recognised tax correction',
            ];
        }

        $lines[] = [
            'chart_account_id' => $receivable->id,
            'debit' => '0.00',
            'credit' => (string) $creditNote->grand_total,
            'description' => "Receivable correction {$creditNote->credit_note_number}",
        ];

        $journal = $this->journalPosting->postNew(
            $actor,
            CarbonImmutable::parse($creditNote->issue_date),
            $lines,
            "Confirmed credit note {$creditNote->credit_note_number}",
            $creditNote,
        );

        if ($invoice instanceof Invoice) {
            $creditNote->forceFill(['recognised_tax_portion' => self::money($recognisedPortionMinor)])->save();

            $invoice->forceFill([
                'recognised_tax_amount' => self::money(
                    max(
                        0,
                        JournalEntryLine::toMinorUnits($invoice->recognised_tax_amount) - $recognisedPortionMinor + $trueUpMinor,
                    ),
                ),
            ])->save();

            if ($trueUpMinor > 0) {
                $this->postTrueUp($actor, $creditNote, $invoice, $trueUpMinor);
            }
        }

        return $journal;
    }

    /**
     * Undoes the tax-recognition side effects of a credit note: the portion it
     * took out of payable is handed back to the invoice and any recognition
     * true-up it posted is reversed. The credit note's own journal is reversed
     * by the caller.
     */
    public function reverseTaxEffects(User $actor, CreditNote $creditNote, Invoice $invoice): void
    {
        $trueUpMinor = 0;

        $trueUps = TaxRecognitionEntry::query()
            ->where('source_type', CreditNote::class)
            ->where('source_id', $creditNote->getKey())
            ->where('direction', 'output')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($trueUps as $trueUp) {
            $reversal = $this->journalPosting->reverse(
                $actor,
                JournalEntry::query()->whereKey($trueUp->journal_entry_id)->sole(),
                CarbonImmutable::today(),
                "Reverse tax recognition for credit note {$creditNote->credit_note_number}",
            );

            $trueUpMinor += JournalEntryLine::toMinorUnits($trueUp->recognised_tax_amount);

            TaxRecognitionEntry::query()->create([
                'tax_date' => now()->toDateString(),
                'direction' => 'output_reversal',
                'tax_type' => 'sales_tax',
                'tax_amount' => '-'.self::money(JournalEntryLine::toMinorUnits($trueUp->tax_amount)),
                'source_type' => CreditNote::class,
                'source_id' => $creditNote->getKey(),
                'invoice_id' => $invoice->getKey(),
                'payment_id' => null,
                'journal_entry_id' => $reversal->getKey(),
                'payment_amount' => null,
                'recognised_tax_amount' => '-'.self::money(JournalEntryLine::toMinorUnits($trueUp->recognised_tax_amount)),
                'recognition_date' => now()->toDateString(),
            ]);
        }

        $restoredMinor = JournalEntryLine::toMinorUnits($creditNote->recognised_tax_portion) - $trueUpMinor;

        $invoice->forceFill([
            'recognised_tax_amount' => self::money(max(
                0,
                JournalEntryLine::toMinorUnits($invoice->recognised_tax_amount) + $restoredMinor,
            )),
        ])->save();
    }

    /**
     * @return array{0: int, 1: int} the credit note tax taken from payable, and the
     *                               deferred tax that must additionally become payable
     */
    private function splitTax(CreditNote $creditNote, Invoice $invoice, int $taxMinor): array
    {
        $taxTotalMinor = JournalEntryLine::toMinorUnits($invoice->tax_total);

        if ($taxTotalMinor <= 0) {
            return [0, 0];
        }

        $recognisedMinor = JournalEntryLine::toMinorUnits($invoice->recognised_tax_amount);
        $creditedBeforeMinor = CreditNote::confirmedTaxMinorForInvoice($invoice->id);

        $effectiveAfterMinor = max(0, $taxTotalMinor - $creditedBeforeMinor - $taxMinor);
        $claimAfterMinor = JournalEntryLine::toMinorUnits($invoice->total_amount)
            - JournalEntryLine::toMinorUnits($invoice->credited_amount)
            - JournalEntryLine::toMinorUnits($creditNote->grand_total);
        $paidMinor = JournalEntryLine::toMinorUnits($invoice->amount_paid);

        $targetMinor = $claimAfterMinor <= 0
            ? $effectiveAfterMinor
            : $this->allocator->allocate(
                totalMinor: $effectiveAfterMinor,
                partMinor: min($paidMinor, $claimAfterMinor),
                wholeMinor: $claimAfterMinor,
                settlesRemainder: $paidMinor >= $claimAfterMinor,
            );

        // The target never exceeds the effective tax left after this credit, so
        // the deferred tax still held always covers the deferred portion taken
        // here: `held - deferredPortion = effectiveAfter - recognisedAfter >= target - recognisedAfter`.
        $deferredHeldMinor = max(0, $taxTotalMinor - $creditedBeforeMinor - $recognisedMinor);

        $recognisedPortionMinor = min($taxMinor, max(0, $recognisedMinor - $targetMinor));
        $deferredPortionMinor = $taxMinor - $recognisedPortionMinor;

        $deferredLeftMinor = $deferredHeldMinor - $deferredPortionMinor;
        $trueUpMinor = min($deferredLeftMinor, max(0, $targetMinor - ($recognisedMinor - $recognisedPortionMinor)));

        return [$recognisedPortionMinor, $trueUpMinor];
    }

    private function postTrueUp(User $actor, CreditNote $creditNote, Invoice $invoice, int $trueUpMinor): void
    {
        $settings = SalesSetting::current()->load(['deferredTaxAccount', 'taxPayableAccount']);
        $deferred = $this->accounts->deferredTax($settings);
        $payable = $this->accounts->taxPayable($settings);

        $date = CarbonImmutable::parse($creditNote->issue_date);
        $amount = self::money($trueUpMinor);

        $entry = TaxRecognitionEntry::query()->create([
            'tax_date' => $date->toDateString(),
            'direction' => 'output',
            'tax_type' => 'sales_tax',
            'tax_amount' => $amount,
            'source_type' => CreditNote::class,
            'source_id' => $creditNote->getKey(),
            'invoice_id' => $invoice->getKey(),
            'payment_id' => null,
            'payment_amount' => null,
            'recognised_tax_amount' => $amount,
            'recognition_date' => $date->toDateString(),
        ]);

        $journal = $this->journalPosting->postNew(
            $actor,
            $date,
            [
                [
                    'chart_account_id' => $deferred->id,
                    'debit' => $amount,
                    'credit' => '0.00',
                    'description' => "Recognise tax after credit note {$creditNote->credit_note_number}",
                ],
                [
                    'chart_account_id' => $payable->id,
                    'debit' => '0.00',
                    'credit' => $amount,
                    'description' => "Sales tax payable {$invoice->invoice_number}",
                ],
            ],
            "Tax recognition after credit note {$creditNote->credit_note_number}",
            $entry,
        );

        $entry->forceFill(['journal_entry_id' => $journal->getKey()])->save();
    }

    private static function money(int $minor): string
    {
        $absolute = abs($minor);
        $value = sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);

        return $minor < 0 ? '-'.$value : $value;
    }
}
