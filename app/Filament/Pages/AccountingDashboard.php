<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AccountingPermission;
use App\Filament\Widgets\AccountingLedgerTrend;
use App\Filament\Widgets\AccountingStatistics;
use App\Filament\Widgets\PeriodCloseReadiness;
use App\Filament\Widgets\TaxPositionThisPeriod;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Accounting's module landing page, surfacing the ledger and receivables/
 * payables signals an accountant checks first: draft entries awaiting
 * posting, outstanding balances, bills awaiting approval, and recent posting
 * activity.
 */
final class AccountingDashboard extends Page
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
    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.accounting_dashboard');
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            AccountingStatistics::class,
            AccountingLedgerTrend::class,
            TaxPositionThisPeriod::class,
            PeriodCloseReadiness::class,
        ];
    }
}
