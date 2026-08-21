<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Models\InventoryStock;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;

final class InventoryStockValue extends ChartWidget
{
    protected ?string $heading = 'Stock value by warehouse';

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
            ->selectRaw('warehouses.name, SUM(inventory_stocks.available_quantity * COALESCE(product_variants.cost_price, 0)) as total')
            ->groupBy('warehouses.id', 'warehouses.name')
            ->orderBy('warehouses.name')
            ->get();

        return [
            'datasets' => [[
                'label' => 'Stock value',
                'data' => $rows->map(fn (object $row): float => (float) $row->total)->all(),
            ]],
            'labels' => $rows->pluck('name')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
