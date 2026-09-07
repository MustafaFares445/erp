<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Filament\Resources\Taxes\Pages\ViewTaxRegister;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Accounting\TaxRegisterService;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The current period's deferred-versus-payable tax position at a glance
 * (WP-2.7, AC-06), so an accountant sees the figure {@see ViewTaxRegister}
 * proves without opening the full report.
 */
final class TaxPositionThisPeriod extends StatsOverviewWidget
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

        return $user->can(AccountingPermission::TaxView->value);
    }

    #[\Override]
    protected function getStats(): array
    {
        $today = CarbonImmutable::now();
        $period = app(FiscalPeriodService::class)->forDate($today);

        if ($period instanceof FiscalPeriod) {
            $from = CarbonImmutable::parse($period->starts_at);
            $to = CarbonImmutable::parse($period->ends_at);
        } else {
            $from = $today->startOfMonth();
            $to = $today->endOfMonth();
        }

        $figures = app(TaxRegisterService::class)->period($from, $to);

        return [
            Stat::make('Output tax charged (deferred)', $figures['output_tax_charged_deferred']),
            Stat::make('Output tax recognised (payable)', $figures['output_tax_recognised_payable']),
            Stat::make('Output tax reversed', $figures['output_tax_reversed']),
            Stat::make('Input tax recognised', $figures['input_tax_recognised']),
            Stat::make('Net tax position', $figures['net_position']),
        ];
    }
}
