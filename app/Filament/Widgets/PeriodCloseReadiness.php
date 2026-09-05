<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Accounting\PeriodCloseChecklistService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The current open period's close readiness at a glance (WP-2.5, GAP-MW-18),
 * read from whatever the checklist last measured — never a fresh (and, for
 * the stock check, side-effecting) run triggered merely by viewing the
 * dashboard.
 */
final class PeriodCloseReadiness extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $user->can(AccountingPermission::FiscalPeriodView->value);
    }

    #[\Override]
    protected function getStats(): array
    {
        $period = FiscalPeriod::query()
            ->where('is_closed', false)
            ->orderBy('starts_at')
            ->first();

        if (! $period instanceof FiscalPeriod) {
            return [Stat::make(__('admin.accounting.close_readiness.no_open_period'), '—')];
        }

        $rows = app(PeriodCloseChecklistService::class)->statusRows($period);
        $mandatoryRows = array_values(array_filter($rows, static fn (array $row): bool => $row['mandatory']));
        $failingCount = count(array_filter($mandatoryRows, static fn (array $row): bool => $row['passed'] === false));
        $passingCount = count(array_filter($mandatoryRows, static fn (array $row): bool => $row['passed'] === true));

        return [
            Stat::make(__('admin.accounting.close_readiness.period'), (string) $period->name),
            Stat::make(
                __('admin.accounting.close_readiness.mandatory_passing'),
                sprintf('%d / %d', $passingCount, count($mandatoryRows)),
            ),
            Stat::make(__('admin.accounting.close_readiness.failing'), (string) $failingCount)
                ->color($failingCount > 0 ? 'danger' : 'success'),
        ];
    }
}
