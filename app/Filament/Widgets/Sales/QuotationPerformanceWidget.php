<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Sales;

use App\Enums\SalesPermission;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use Filament\Widgets\Widget;

final class QuotationPerformanceWidget extends Widget
{
    use InteractsWithDashboardFilters;

    protected string $view = 'filament.widgets.sales.quotation-performance';

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
            ['key' => 'accepted', 'label' => __('dashboards.sales.cards.accepted'), 'color' => 'success', 'data' => $performance['accepted']],
            ['key' => 'awaiting_decision', 'label' => __('dashboards.sales.cards.awaiting_decision'), 'color' => 'info', 'data' => $performance['awaiting_decision']],
            ['key' => 'rejected_or_expired', 'label' => __('dashboards.sales.cards.rejected_or_expired'), 'color' => 'danger', 'data' => $performance['rejected_or_expired']],
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
