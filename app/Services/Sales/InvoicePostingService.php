<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;

final readonly class InvoicePostingService
{
    public function __construct(
        private SalesAccountResolver $accounts,
        private JournalPostingService $journalPosting,
    ) {}

    public function post(User $actor, Invoice $invoice): JournalEntry
    {
        $settings = SalesSetting::current()->load([
            'receivableAccount', 'revenueAccount', 'deferredTaxAccount',
        ]);

        $receivable = $this->accounts->receivable($settings);
        $revenue = $this->accounts->revenue($settings);
        $deferredTax = $this->accounts->deferredTax($settings);

        $lines = [
            [
                'chart_account_id' => (int) $receivable->getKey(),
                'debit' => (string) $invoice->total_amount,
                'credit' => '0.00',
                'description' => "Receivable {$invoice->invoice_number}",
            ],
            [
                'chart_account_id' => (int) $revenue->getKey(),
                'debit' => '0.00',
                'credit' => (string) $invoice->subtotal,
                'description' => "Revenue {$invoice->invoice_number}",
            ],
        ];

        if ((float) $invoice->tax_total > 0.0) {
            $lines[] = [
                'chart_account_id' => (int) $deferredTax->getKey(),
                'debit' => '0.00',
                'credit' => (string) $invoice->tax_total,
                'description' => "Deferred sales tax {$invoice->invoice_number}",
            ];
        }

        return $this->journalPosting->postNew(
            $actor,
            CarbonImmutable::parse($invoice->invoice_date),
            $lines,
            "Issued invoice {$invoice->invoice_number}",
            $invoice,
        );
    }
}
