<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Sales;

use App\Enums\SalesPermission;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use Filament\Widgets\ChartWidget;

final class TopProductsChart extends ChartWidget
{
    use InteractsWithDashboardFilters;

    protected ?string $maxHeight = '300px';

    #[\Override]
    public function getHeading(): string
    {
        return __('dashboards.sales.charts.top_products');
    }

    #[\Override]
    public function getEmptyStateHeading(): string
    {
        return __('dashboards.sales.empty.products');
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
        $products = app(SalesDashboardMetricsService::class)->topProducts($filters);

        return [
            'datasets' => [[
                'label' => __('dashboards.sales.charts.sales_value'),
                'data' => array_column($products, 'value'),
                'backgroundColor' => '#22c55e',
            ]],
            'labels' => array_column($products, 'label'),
        ];
    }

    #[\Override]
    protected function getType(): string
    {
        return 'bar';
    }

    #[\Override]
    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
