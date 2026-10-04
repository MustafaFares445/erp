<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Sales;

use App\Enums\SalesPermission;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use Filament\Widgets\Widget;

/**
 * Operational backlog list — deliberately not date-filtered by the
 * selected period (these are current-state buckets, same as the
 * per-resource scopes they reuse), but still respect the customer/
 * salesperson filters.
 */
final class RequiresAttentionWidget extends Widget
{
    protected static bool $isLazy = false;

    use InteractsWithDashboardFilters;

    protected string $view = 'filament.widgets.sales.requires-attention';

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
            'items' => app(SalesDashboardMetricsService::class)->attentionItems($filters),
        ];
    }
}
