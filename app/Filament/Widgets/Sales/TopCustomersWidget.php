<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Sales;

use App\Enums\SalesPermission;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use App\Services\Settings\CurrencyCatalogService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

final class TopCustomersWidget extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.sales.top-customers';

    protected int|string|array $columnSpan = ['default' => 1, 'lg' => 4];

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SalesPermission::OrderView->value) ?? false;
    }

    /** @return array<string, mixed> */
    #[\Override]
    protected function getViewData(): array
    {
        $filters = SalesDashboardFilters::fromPageFilters($this->pageFilters ?? []);

        return [
            'customers' => app(SalesDashboardMetricsService::class)->topCustomers($filters),
            'currency' => app(CurrencyCatalogService::class)->defaultCode(),
        ];
    }
}
