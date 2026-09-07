<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\ReceivableWriteOff;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Sales\SalesAccountResolver;
use Carbon\CarbonImmutable;
use DomainException;

final readonly class WriteOffPostingService
{
    public function __construct(
        private SalesAccountResolver $accounts,
        private JournalPostingService $journalPosting,
    ) {}

    public function post(
        User $actor,
        ReceivableWriteOff $writeOff,
        Invoice $invoice,
        int $taxAmountMinor,
    ): JournalEntry {
        $amountMinor = (int) $writeOff->amount_minor;

        if ($amountMinor <= 0 || $taxAmountMinor < 0 || $taxAmountMinor > $amountMinor) {
            throw new DomainException('A receivable write-off requires a positive amount and a valid deferred-tax portion.');
        }

        $settings = SalesSetting::current()->load([
            'receivableAccount',
            'deferredTaxAccount',
            'badDebtExpenseAccount',
        ]);

        $receivable = $this->accounts->receivable($settings);
        $deferredTax = $this->accounts->deferredTax($settings);
        $badDebt = $this->accounts->badDebtExpense($settings);

        $expenseMinor = $amountMinor - $taxAmountMinor;
        $lines = [];

        if ($expenseMinor > 0) {
            $lines[] = [
                'chart_account_id' => (int) $badDebt->getKey(),
                'debit' => self::money($expenseMinor),
                'credit' => '0.00',
                'description' => "Bad debt {$invoice->invoice_number}",
            ];
        }

        if ($taxAmountMinor > 0) {
            $lines[] = [
                'chart_account_id' => (int) $deferredTax->getKey(),
                'debit' => self::money($taxAmountMinor),
                'credit' => '0.00',
                'description' => "Release deferred tax {$invoice->invoice_number}",
            ];
        }

        $lines[] = [
            'chart_account_id' => (int) $receivable->getKey(),
            'debit' => '0.00',
            'credit' => self::money($amountMinor),
            'description' => "Write off receivable {$invoice->invoice_number}",
        ];

        $entry = $this->journalPosting->postNew(
            $actor,
            CarbonImmutable::parse($writeOff->created_at),
            $lines,
            "Receivable write-off {$writeOff->write_off_number}",
            $writeOff,
        );

        if ((int) $entry->fiscal_period_id !== (int) $writeOff->fiscal_period_id) {
            throw new DomainException('The write-off posting resolved to a different fiscal period than the recorded document.');
        }

        return $entry;
    }

    private static function money(int $minor): string
    {
        $absolute = abs($minor);
        $value = sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);

        return $minor < 0 ? '-'.$value : $value;
    }
}
