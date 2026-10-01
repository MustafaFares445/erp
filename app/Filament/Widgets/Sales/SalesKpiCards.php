<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Sales;

use App\Enums\SalesPermission;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardLinks;
use App\Services\Sales\SalesDashboardMetricsService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * The four primary KPI cards: confirmed order value (the dashboard's
 * canonical "Sales" figure, see {@see SalesDashboardMetricsService}),
 * confirmed order count, average order value, and quote-to-order
 * conversion.
 */
final class SalesKpiCards extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SalesPermission::OrderView->value) ?? false;
    }

    #[\Override]
    protected function getStats(): array
    {
        $filters = SalesDashboardFilters::fromPageFilters($this->pageFilters ?? []);
        $kpis = app(SalesDashboardMetricsService::class)->kpis($filters);
        $currency = $kpis['currency'];

        return [
            Stat::make(__('Confirmed order value'), self::money($kpis['value'], $currency))
                ->description(self::changeDescription($kpis['value_change_percent']))
                ->descriptionIcon(self::changeIcon($kpis['value_change_percent']))
                ->color(self::changeColor($kpis['value_change_percent']))
                ->icon(Heroicon::OutlinedBanknotes)
                ->url(SalesDashboardLinks::orders('active', $filters->customerId)),
            Stat::make(__('Confirmed orders'), (string) $kpis['count'])
                ->description(self::changeDescription($kpis['count_change_percent']))
                ->descriptionIcon(self::changeIcon($kpis['count_change_percent']))
                ->color(self::changeColor($kpis['count_change_percent']))
                ->icon(Heroicon::OutlinedShoppingCart)
                ->url(SalesDashboardLinks::orders('active', $filters->customerId)),
            Stat::make(__('Average order value'), $kpis['average_order_value'] !== null ? self::money($kpis['average_order_value'], $currency) : '—')
                ->description($kpis['average_order_value'] !== null ? 'Per confirmed order, selected period' : 'No confirmed orders in this period')
                ->icon(Heroicon::OutlinedCalculator),
            Stat::make(__('Quote → order conversion'), $kpis['conversion_percent'] !== null ? number_format($kpis['conversion_percent'], 1).'%' : '—')
                ->description("{$kpis['conversion_numerator']} of {$kpis['conversion_denominator']} decided quotations converted")
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->url(SalesDashboardLinks::quotations('accepted', $filters->customerId, $filters->employeeId)),
        ];
    }

    private static function money(float $amount, string $currency): string
    {
        $formatted = Number::currency($amount, $currency);

        return $formatted === false ? "{$currency} {$amount}" : $formatted;
    }

    private static function changeDescription(?float $percent): string
    {
        if ($percent === null) {
            return 'No comparable data for the previous period';
        }

        $sign = $percent > 0 ? '+' : '';

        return "{$sign}".number_format($percent, 1).'% vs previous period';
    }

    private static function changeIcon(?float $percent): ?Heroicon
    {
        return match (true) {
            $percent === null => null,
            $percent > 0 => Heroicon::OutlinedArrowTrendingUp,
            $percent < 0 => Heroicon::OutlinedArrowTrendingDown,
            default => null,
        };
    }

    private static function changeColor(?float $percent): string
    {
        return match (true) {
            $percent === null => 'gray',
            $percent > 0 => 'success',
            $percent < 0 => 'danger',
            default => 'gray',
        };
    }
}
