<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Sales;

use App\Enums\SalesPermission;
use App\Filament\Widgets\Concerns\BuildsTrendStats;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardLinks;
use App\Services\Sales\SalesDashboardMetricsService;
use App\Support\MoneyFormatter;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four primary KPI cards: confirmed order value (the dashboard's
 * canonical "Sales" figure, see {@see SalesDashboardMetricsService}),
 * confirmed order count, average order value, and quote-to-order
 * conversion. Value and count carry the selected window's sparkline.
 */
final class SalesKpiCards extends StatsOverviewWidget
{
    use BuildsTrendStats;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SalesPermission::OrderView->value) ?? false;
    }

    #[\Override]
    protected function getStats(): array
    {
        $filters = SalesDashboardFilters::fromPageFilters($this->pageFilters ?? []);
        $metrics = app(SalesDashboardMetricsService::class);
        $kpis = $metrics->kpis($filters);
        $trend = $metrics->salesTrend($filters);
        $currency = $kpis['currency'];
        $ordersUrl = SalesDashboardLinks::orders('active', $filters->customerId);

        $averageOrderValue = $kpis['average_order_value'] ?? 0.0;
        $previousAverageOrderValue = $kpis['count_previous'] > 0 ? $kpis['value_previous'] / $kpis['count_previous'] : 0.0;

        return [
            $this->trendStat(
                __('dashboards.sales.kpis.confirmed_value'),
                MoneyFormatter::formatAmount($kpis['value'], $currency),
                $kpis['value'],
                $kpis['value_previous'],
                $trend['current'],
                Heroicon::OutlinedBanknotes,
                $ordersUrl,
            ),
            $this->trendStat(
                __('dashboards.sales.kpis.confirmed_orders'),
                (string) $kpis['count'],
                $kpis['count'],
                $kpis['count_previous'],
                $trend['current_counts'],
                Heroicon::OutlinedShoppingCart,
                $ordersUrl,
            ),
            $this->trendStat(
                __('dashboards.sales.kpis.average_order_value'),
                $kpis['average_order_value'] !== null ? MoneyFormatter::formatAmount($averageOrderValue, $currency) : '—',
                $averageOrderValue,
                $previousAverageOrderValue,
                icon: Heroicon::OutlinedCalculator,
            ),
            Stat::make(
                __('dashboards.sales.kpis.conversion'),
                $kpis['conversion_percent'] !== null ? number_format($kpis['conversion_percent'], 1).'%' : '—',
            )
                ->description(__('dashboards.sales.kpis.conversion_detail', [
                    'converted' => $kpis['conversion_numerator'],
                    'decided' => $kpis['conversion_denominator'],
                ]))
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->url(SalesDashboardLinks::quotations('accepted', $filters->customerId, $filters->employeeId)),
        ];
    }
}
