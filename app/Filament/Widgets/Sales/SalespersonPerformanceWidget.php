<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Sales;

use App\Enums\SalesPermission;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use App\Services\Settings\CurrencyCatalogService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Hidden entirely when no quotation in the system carries a salesperson —
 * ownership is never inferred from an unrelated column (see
 * {@see SalesDashboardMetricsService} for why `Order.responsible_id`
 * doesn't count).
 */
final class SalespersonPerformanceWidget extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.sales.salesperson-performance';

    protected int|string|array $columnSpan = ['default' => 1, 'lg' => 4];

    #[\Override]
    public static function canView(): bool
    {
        if (! (auth()->user()?->can(SalesPermission::QuotationView->value) ?? false)) {
            return false;
        }

        return app(SalesDashboardMetricsService::class)->hasSalespersonData();
    }

    /** @return array<string, mixed> */
    #[\Override]
    protected function getViewData(): array
    {
        $filters = SalesDashboardFilters::fromPageFilters($this->pageFilters ?? []);

        return [
            'salespeople' => app(SalesDashboardMetricsService::class)->salespersonPerformance($filters),
            'currency' => app(CurrencyCatalogService::class)->defaultCode(),
        ];
    }
}
