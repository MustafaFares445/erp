<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;

final readonly class CreditNotePostingService
{
    public function __construct(
        private SalesAccountResolver $accounts,
        private JournalPostingService $journalPosting,
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

        $share = $invoice instanceof Invoice && (float) $invoice->tax_total > 0.0
            ? min(1.0, max(0.0, (float) $invoice->recognised_tax_amount / (float) $invoice->tax_total))
            : 0.0;

        $recognisedPortion = round((float) $creditNote->tax_total * $share, 2);
        $deferredPortion = round((float) $creditNote->tax_total - $recognisedPortion, 2);

        $lines = [
            [
                'chart_account_id' => (int) $revenue->getKey(),
                'debit' => (string) $creditNote->subtotal,
                'credit' => '0.00',
                'description' => "Revenue correction {$creditNote->credit_note_number}",
            ],
        ];

        if ($deferredPortion > 0.0) {
            $lines[] = [
                'chart_account_id' => (int) $deferred->getKey(),
                'debit' => number_format($deferredPortion, 2, '.', ''),
                'credit' => '0.00',
                'description' => 'Deferred tax correction',
            ];
        }

        if ($recognisedPortion > 0.0) {
            $lines[] = [
                'chart_account_id' => (int) $payable->getKey(),
                'debit' => number_format($recognisedPortion, 2, '.', ''),
                'credit' => '0.00',
                'description' => 'Recognised tax correction',
            ];
        }

        $lines[] = [
            'chart_account_id' => (int) $receivable->getKey(),
            'debit' => '0.00',
            'credit' => (string) $creditNote->grand_total,
            'description' => "Receivable correction {$creditNote->credit_note_number}",
        ];

        return $this->journalPosting->postNew(
            $actor,
            CarbonImmutable::parse($creditNote->issue_date),
            $lines,
            "Confirmed credit note {$creditNote->credit_note_number}",
            $creditNote,
        );
    }
}
