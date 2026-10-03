<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Sales;

use App\Enums\SalesPermission;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use Filament\Widgets\Widget;

/**
 * Horizontal-bar rendering of the quotation → order → delivery → invoice
 * funnel (no bundled Filament/Chart.js chart type supports a true funnel
 * shape cleanly, so this follows the spec's documented fallback).
 */
final class SalesFunnelWidget extends Widget
{
    use InteractsWithDashboardFilters;

    protected string $view = 'filament.widgets.sales.sales-funnel';

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
        $funnel = app(SalesDashboardMetricsService::class)->salesFunnel($filters);

        $maxCount = max(1, ...array_column($funnel['stages'], 'count'));

        return [
            'stages' => $funnel['stages'],
            'currency' => $funnel['currency'],
            'maxCount' => $maxCount,
        ];
    }
}
