<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AccountingPermission;
use App\Enums\JournalEntryStatus;
use App\Enums\WriteOffStatus;
use App\Filament\Resources\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Widgets\Concerns\BuildsTrendStats;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\ReceivableWriteOff;
use App\Services\Accounting\TaxRegisterService;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\MoneyFormatter;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Accounting's four headline cards. Receivables and payables are live
 * balances (with the selected window's invoicing/billing as the sparkline);
 * the net tax position compares the selected window with the previous one;
 * "awaiting action" counts the drafts an accountant still has to post or
 * approve.
 */
final class AccountingStatistics extends StatsOverviewWidget
{
    use BuildsTrendStats;
    use InteractsWithDashboardFilters;

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
        return [
            $this->receivablesStat(),
            $this->payablesStat(),
            $this->netTaxStat(),
            $this->awaitingActionStat(),
        ];
    }

    private function receivablesStat(): Stat
    {
        $period = $this->dashboardPeriod();

        // Lifecycle and settlement are independent axes. Every issued invoice
        // contributes its live balance, including any approved write-off.
        $outstandingMinor = (int) Invoice::query()
            ->with('writeOffs')
            ->whereNotNull('issued_at')
            ->get()
            ->sum(fn (Invoice $invoice): int => $invoice->outstandingMinor());

        $badDebtMinor = self::toInt(ReceivableWriteOff::query()
            ->where('status', WriteOffStatus::Approved->value)
            ->whereBetween('approved_at', [$period->from, $period->to])
            ->selectRaw('COALESCE(SUM(amount_minor - tax_amount_minor), 0) as bad_debt_minor')
            ->value('bad_debt_minor'));

        $invoiced = Invoice::query()
            ->whereBetween('issued_at', [$period->from, $period->to])
            ->get(['issued_at', 'total_amount'])
            ->map(fn (Invoice $invoice): array => [$invoice->issued_at, $invoice->total_amount]);

        return Stat::make(__('dashboards.accounting.kpis.receivables'), MoneyFormatter::format($outstandingMinor))
            ->description(__('dashboards.accounting.kpis.bad_debt', ['value' => MoneyFormatter::format($badDebtMinor)]))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->chart($period->sumSeries($invoiced))
            ->chartColor('primary')
            ->url(AccountsReceivableResource::getUrl());
    }

    private function payablesStat(): Stat
    {
        $period = $this->dashboardPeriod();

        // Mirrors AccountsPayableResource::getEloquentQuery()'s status filter.
        $outstanding = Bill::query()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->selectRaw('COALESCE(SUM(total_amount - amount_paid), 0) as outstanding')
            ->value('outstanding');

        $bills = Bill::query()
            ->whereDate('bill_date', '>=', $period->from->toDateString())
            ->whereDate('bill_date', '<=', $period->to->toDateString())
            ->whereNot('status', 'draft')
            ->get(['bill_date', 'total_amount']);

        return Stat::make(__('dashboards.accounting.kpis.payables'), MoneyFormatter::formatAmount(is_numeric($outstanding) ? $outstanding : 0))
            ->description(__('dashboards.accounting.kpis.billed', [
                'value' => MoneyFormatter::formatAmount(DashboardPeriod::toFloat($bills->sum('total_amount'))),
            ]))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->chart($period->sumSeries($bills->map(fn (Bill $bill): array => [$bill->bill_date, $bill->total_amount])))
            ->chartColor('primary')
            ->url(AccountsPayableResource::getUrl());
    }

    private function netTaxStat(): Stat
    {
        $period = $this->dashboardPeriod();
        $taxRegister = app(TaxRegisterService::class);

        $current = (float) $taxRegister->period($period->from, $period->to)['net_position'];
        $previous = (float) $taxRegister->period($period->previousFrom, $period->previousTo)['net_position'];

        return $this->trendStat(
            __('dashboards.accounting.kpis.net_tax'),
            MoneyFormatter::formatAmount($current),
            $current,
            $previous,
            icon: Heroicon::OutlinedReceiptPercent,
            higherIsBetter: false,
        );
    }

    private function awaitingActionStat(): Stat
    {
        $draftEntries = JournalEntry::query()
            ->where('status', JournalEntryStatus::Draft->value)
            ->count();

        // A bill has no dedicated "pending approval" status: BillPolicy::approve()
        // and BillResource's approve action both gate on Bill::isDraft(), so a
        // draft bill *is* the one awaiting approval.
        $draftBills = Bill::query()
            ->where('status', 'draft')
            ->count();

        $total = $draftEntries + $draftBills;

        return Stat::make(__('dashboards.accounting.kpis.awaiting_action'), (string) $total)
            ->description(__('dashboards.accounting.kpis.awaiting_detail', ['entries' => $draftEntries, 'bills' => $draftBills]))
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color($total > 0 ? 'warning' : 'success')
            ->url(JournalEntryResource::getUrl());
    }

    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : 0;
    }
}
