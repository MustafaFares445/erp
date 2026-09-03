<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AccountingPermission;
use App\Enums\JournalEntryStatus;
use App\Enums\WriteOffStatus;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\ReceivableWriteOff;
use App\Services\Accounting\FiscalPeriodService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class AccountingStatistics extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();
        if ($user?->can(AccountingPermission::JournalEntryView->value) ?? false) {
            return true;
        }
        if ($user?->can(AccountingPermission::ReceivableView->value) ?? false) {
            return true;
        }

        return (bool) ($user?->can(AccountingPermission::PayableView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        $draftJournalEntries = JournalEntry::query()
            ->where('status', JournalEntryStatus::Draft->value)
            ->count();

        // Lifecycle and settlement are independent axes. Every issued invoice
        // contributes its live balance, including any approved write-off.
        $receivablesOutstandingMinor = (int) Invoice::query()
            ->with('writeOffs')
            ->whereNotNull('issued_at')
            ->get()
            ->sum(fn (Invoice $invoice): int => $invoice->outstandingMinor());
        $receivablesOutstanding = self::formatMinor($receivablesOutstandingMinor);

        // Mirrors AccountsPayableResource::getEloquentQuery()'s status filter.
        $payablesOutstanding = $this->toFloat(
            Bill::query()
                ->whereIn('status', ['approved', 'partially_paid'])
                ->selectRaw('COALESCE(SUM(total_amount - amount_paid), 0) as outstanding')
                ->value('outstanding')
        );

        $currentPeriod = app(FiscalPeriodService::class)->forDate(now());
        $badDebtThisPeriodMinor = $currentPeriod === null
            ? 0
            : (int) ReceivableWriteOff::query()
                ->where('status', WriteOffStatus::Approved->value)
                ->where('fiscal_period_id', $currentPeriod->getKey())
                ->selectRaw('COALESCE(SUM(amount_minor - tax_amount_minor), 0) as bad_debt_minor')
                ->value('bad_debt_minor');

        // A bill has no dedicated "pending approval" status: BillPolicy::approve()
        // and BillResource's approve action both gate on Bill::isDraft(), so a
        // draft bill *is* the one awaiting approval.
        $billsPendingApproval = Bill::query()
            ->where('status', 'draft')
            ->count();

        return [
            Stat::make('Draft journal entries pending posting', $draftJournalEntries),
            Stat::make('Receivables outstanding', $receivablesOutstanding),
            Stat::make('Payables outstanding', number_format($payablesOutstanding, 2)),
            Stat::make('Bills pending approval', $billsPendingApproval),
            Stat::make('Bad debt this period', self::formatMinor($badDebtThisPeriodMinor)),
        ];
    }

    private static function formatMinor(int $minor): string
    {
        $absolute = abs($minor);
        $value = sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);

        return $minor < 0 ? '-'.$value : $value;
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
