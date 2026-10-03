<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Sales;

use App\Enums\SalesPermission;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use Filament\Widgets\ChartWidget;

/**
 * Line chart of confirmed order value (see
 * {@see SalesDashboardMetricsService} for the definition), current period
 * vs the equal-length previous period, bucketed at the granularity the
 * selected period calls for (hourly/daily/weekly/monthly).
 */
final class SalesPerformanceChart extends ChartWidget
{
    use InteractsWithDashboardFilters;

    protected ?string $maxHeight = '300px';

    #[\Override]
    public function getHeading(): string
    {
        return __('dashboards.sales.charts.performance');
    }

    #[\Override]
    public function getEmptyStateHeading(): string
    {
        return __('dashboards.sales.empty.sales');
    }

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SalesPermission::OrderView->value) ?? false;
    }

    #[\Override]
    protected function getData(): array
    {
        $filters = SalesDashboardFilters::fromPageFilters($this->pageFilters ?? []);
        $trend = app(SalesDashboardMetricsService::class)->salesTrend($filters);

        return [
            'datasets' => [
                [
                    'label' => __('dashboards.charts.selected_period'),
                    'data' => $trend['current'],
                    'borderColor' => '#22c55e',
                    'backgroundColor' => 'transparent',
                ],
                [
                    'label' => __('dashboards.charts.previous_period'),
                    'data' => $trend['previous'],
                    'borderColor' => '#94a3b8',
                    'backgroundColor' => 'transparent',
                    'borderDash' => [6, 4],
                ],
            ],
            'labels' => $trend['labels'],
        ];
    }

    #[\Override]
    protected function getType(): string
    {
        return 'line';
    }

    #[\Override]
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true],
            ],
            'scales' => [
                'y' => ['beginAtZero' => true],
            ],
        ];
    }
}
