<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Sales;

use App\Enums\SalesPermission;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

final class RecentSalesActivityWidget extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.sales.recent-sales-activity';

    protected int|string|array $columnSpan = ['default' => 1, 'lg' => 12];

    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();
        if ($user?->can(SalesPermission::QuotationView->value) ?? false) {
            return true;
        }

        return (bool) ($user?->can(SalesPermission::OrderView->value) ?? false);
    }

    /** @return array<string, mixed> */
    #[\Override]
    protected function getViewData(): array
    {
        $filters = SalesDashboardFilters::fromPageFilters($this->pageFilters ?? []);

        return [
            'events' => app(SalesDashboardMetricsService::class)->recentActivity($filters),
        ];
    }
}
