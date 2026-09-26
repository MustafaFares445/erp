<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Sales;

use App\Enums\SalesPermission;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

final class QuotationPerformanceWidget extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.sales.quotation-performance';

    protected int|string|array $columnSpan = ['default' => 1, 'lg' => 5];

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SalesPermission::QuotationView->value) ?? false;
    }

    /** @return array<string, mixed> */
    #[\Override]
    protected function getViewData(): array
    {
        $filters = SalesDashboardFilters::fromPageFilters($this->pageFilters ?? []);
        $performance = app(SalesDashboardMetricsService::class)->quotationPerformance($filters);

        $segments = [
            ['key' => 'accepted', 'label' => 'Accepted', 'color' => 'success', 'data' => $performance['accepted']],
            ['key' => 'awaiting_decision', 'label' => 'Awaiting decision', 'color' => 'info', 'data' => $performance['awaiting_decision']],
            ['key' => 'rejected_or_expired', 'label' => 'Rejected / expired', 'color' => 'danger', 'data' => $performance['rejected_or_expired']],
        ];

        $totalCount = array_sum(array_map(static fn (array $segment): int => $segment['data']['count'], $segments));

        return [
            'open' => $performance['open'],
            'segments' => $segments,
            'totalCount' => $totalCount,
            'conversionPercent' => $performance['conversion_percent'],
            'medianDaysToDecision' => $performance['median_days_to_decision'],
            'currency' => $performance['currency'],
        ];
    }
}
