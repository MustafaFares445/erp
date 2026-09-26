<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Sales;

use App\Enums\SalesPermission;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

final class TopProductsChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Top products';

    protected int|string|array $columnSpan = ['default' => 1, 'lg' => 4];

    protected ?string $maxHeight = '280px';

    protected ?string $emptyStateHeading = 'No product sales exist for the selected period.';

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
                'label' => 'Sales value',
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
