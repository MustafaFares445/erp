<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\InventoryStock;
use App\Models\WarehouseReplenishmentPolicy;
use App\Support\QuantityFormatter;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock positions that are out of stock or at/below their replenishment
 * minimum, emptiest first, optionally for one warehouse.
 */
final class InventoryLowStock extends TableWidget
{
    protected static bool $isLazy = false;

    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(InventoryPermission::StockView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('admin.inventory.dashboard.reorder_needed'))
            ->query(fn (): Builder => InventoryStock::query()
                ->select('inventory_stocks.*')
                ->addSelect(['policy_minimum' => WarehouseReplenishmentPolicy::query()
                    ->select('min_quantity')
                    ->whereColumn('warehouse_replenishment_policies.warehouse_id', 'inventory_stocks.warehouse_id')
                    ->whereColumn('warehouse_replenishment_policies.product_variant_id', 'inventory_stocks.product_variant_id')
                    ->limit(1)])
                ->with(['productVariant:id,sku,name', 'warehouse:id,name'])
                ->where(function (Builder $query): void {
                    $query->where('available_quantity', '<=', 0)
                        ->orWhereExists(WarehouseReplenishmentPolicy::breachedSubquery());
                })
                ->when($this->dashboardFilter('warehouseId'), static fn (Builder $query, int $warehouseId): Builder => $query->where('inventory_stocks.warehouse_id', $warehouseId))
                ->orderBy('available_quantity'))
            ->recordUrl(fn (InventoryStock $record): string => StockLevelResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('productVariant.name')
                    ->label(__('admin.inventory.stock.variant_name'))
                    ->description(fn (InventoryStock $record): ?string => $record->productVariant?->sku)
                    ->weight(FontWeight::Medium),
                TextColumn::make('warehouse.name')
                    ->label(__('admin.inventory.stock.warehouse_name')),
                TextColumn::make('available_quantity')
                    ->label(__('admin.inventory.stock.available_quantity'))
                    ->formatStateUsing(fn (mixed $state): string => QuantityFormatter::display($state))
                    ->color(fn (InventoryStock $record): string => (float) $record->available_quantity <= 0 ? 'danger' : 'warning')
                    ->weight(FontWeight::Medium),
                TextColumn::make('policy_minimum')
                    ->label(__('admin.inventory.stock.reorder_level'))
                    ->formatStateUsing(fn (mixed $state): string => QuantityFormatter::display($state))
                    ->placeholder('—'),
            ]);
    }
}
