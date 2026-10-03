<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AccountingPermission;
use App\Filament\Widgets\AccountingLedgerTrend;
use App\Filament\Widgets\AccountingStatistics;
use App\Filament\Widgets\AccountingTopReceivables;
use App\Filament\Widgets\PeriodCloseReadiness;
use App\Filament\Widgets\TaxPositionThisPeriod;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Accounting's module landing page: receivable/payable balances, the net
 * tax position and drafts awaiting action, then posting activity beside the
 * tax breakdown, then the close checklist beside the customers to chase.
 */
final class AccountingDashboard extends ModuleDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    #[\Override]
    public static function canAccess(): bool
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
    public function getTitle(): string
    {
        return __('admin.resources.accounting_dashboard');
    }

    #[\Override]
    protected function getDashboardWidgets(): array
    {
        return [
            AccountingStatistics::class,
            [AccountingLedgerTrend::class, TaxPositionThisPeriod::class],
            [PeriodCloseReadiness::class, AccountingTopReceivables::class],
        ];
    }
}
