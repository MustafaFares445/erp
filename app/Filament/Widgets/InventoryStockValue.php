<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\InventoryStock;
use App\Services\Settings\CurrencyCatalogService;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Usable stock value (available quantity × cost price) per warehouse, in
 * the default currency.
 */
final class InventoryStockValue extends ChartWidget
{
    use InteractsWithDashboardFilters;

    protected ?string $maxHeight = '300px';

    #[\Override]
    public function getHeading(): string
    {
        return __('admin.inventory.dashboard.stock_value_by_warehouse');
    }

    #[\Override]
    public static function canView(): bool
    {
        $actor = auth()->user();

        return $actor?->can(InventoryPermission::StockView->value) === true
            && $actor->can(InventoryPermission::PricingView->value);
    }

    #[\Override]
    protected function getData(): array
    {
        /** @var Collection<int, object{name: string, total: numeric-string|float|int}> $rows */
        $rows = InventoryStock::query()
            ->join('warehouses', 'warehouses.id', '=', 'inventory_stocks.warehouse_id')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.product_variant_id')
            ->when($this->dashboardFilter('warehouseId'), static fn (Builder $query, int $warehouseId): Builder => $query->where('inventory_stocks.warehouse_id', $warehouseId))
            ->selectRaw('warehouses.name, SUM(inventory_stocks.available_quantity * COALESCE(product_variants.cost_price, 0)) as total')
            ->groupBy('warehouses.id', 'warehouses.name')
            ->orderBy('warehouses.name')
            ->get();

        return [
            'datasets' => [[
                'label' => __('dashboards.inventory.charts.stock_value', ['currency' => app(CurrencyCatalogService::class)->defaultCode()]),
                'data' => $rows->map(fn (object $row): float => (float) $row->total)->all(),
                'backgroundColor' => '#3b82f6',
            ]],
            'labels' => $rows->pluck('name')->all(),
        ];
    }

    #[\Override]
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => true]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
