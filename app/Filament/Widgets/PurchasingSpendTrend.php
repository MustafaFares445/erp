<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\PurchasePermission;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\PurchaseOrder;
use App\Services\Settings\CurrencyCatalogService;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * PO spend per bucket of the selected window against the previous window,
 * in the default currency only — there is no cross-currency summation.
 * Manager analytics: visible to approvers.
 */
final class PurchasingSpendTrend extends ChartWidget
{
    use InteractsWithDashboardFilters;

    protected ?string $maxHeight = '300px';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(PurchasePermission::OrderApprove->value) ?? false;
    }

    #[\Override]
    public function getHeading(): string
    {
        return __('dashboards.purchasing.charts.spend', ['currency' => app(CurrencyCatalogService::class)->defaultCode()]);
    }

    #[\Override]
    protected function getData(): array
    {
        $period = $this->dashboardPeriod();

        return [
            'datasets' => [
                [
                    'label' => __('dashboards.charts.selected_period'),
                    'data' => $period->sumSeries($this->spend($period->from, $period->to)),
                    'borderColor' => '#22c55e',
                    'backgroundColor' => 'transparent',
                ],
                [
                    'label' => __('dashboards.charts.previous_period'),
                    'data' => $period->sumSeries($this->spend($period->previousFrom, $period->previousTo), previous: true),
                    'borderColor' => '#94a3b8',
                    'backgroundColor' => 'transparent',
                    'borderDash' => [6, 4],
                ],
            ],
            'labels' => $period->labels(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @return array<int, array{0: mixed, 1: mixed}> */
    private function spend(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return PurchaseOrder::query()
            ->where('currency_code', app(CurrencyCatalogService::class)->defaultCode())
            ->whereDate('ordered_at', '>=', $from->toDateString())
            ->whereDate('ordered_at', '<=', $to->toDateString())
            ->when($this->dashboardFilter('supplierId'), static fn (Builder $query, int $supplierId): Builder => $query->where('supplier_id', $supplierId))
            ->get(['ordered_at', 'total_amount'])
            ->map(static fn (PurchaseOrder $order): array => [$order->ordered_at, $order->total_amount])
            ->values()
            ->all();
    }
}
