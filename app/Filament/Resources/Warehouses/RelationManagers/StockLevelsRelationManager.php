<?php

declare(strict_types=1);

namespace App\Filament\Resources\Warehouses\RelationManagers;

use App\Models\WarehouseReplenishmentPolicy;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * READ-ONLY per-warehouse stock view (FI-2 read model surfaced on the
 * warehouse's View/Edit page). No create/edit/delete action is registered;
 * Filament's default read-only mode on the View page additionally hides any
 * that a future edit would otherwise expose.
 *
 * Deliberately references stock only through the `stocks` relationship
 * name/attributes — never `App\Models\InventoryStock` directly — because
 * this namespace is NOT excepted by the architecture guard in
 * tests/Unit/ArchTest.php (only StockLevels/StockMovements are). See
 * specs/002-warehouses-stock-visibility/research.md R1.
 */
final class StockLevelsRelationManager extends RelationManager
{
    protected static string $relationship = 'stocks';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('productVariant.sku')
                    ->label(__('admin.inventory.stock.variant')),
                TextColumn::make('productVariant.name'),
                TextColumn::make('on_hand_quantity'),
                TextColumn::make('reserved_quantity'),
                TextColumn::make('available_quantity'),
                TextColumn::make('reorder_level')
                    ->label(__('admin.inventory.stock.reorder_level'))
                    ->state(fn (Model $record): ?string => $this->policyFor($record)?->min_quantity),
                TextColumn::make('low_stock')
                    ->label(__('admin.inventory.stock.low_stock'))
                    ->state(fn (Model $record): string => $this->isLowStock($record)
                        ? __('admin.inventory.stock.low_stock')
                        : '')
                    ->badge()
                    ->color(fn (Model $record): string => $this->isLowStock($record) ? 'danger' : 'gray'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * Looked up by `(warehouse_id, product_variant_id)` rather than through
     * `InventoryStock` — this namespace's self-imposed rule (see the class
     * docblock) is to never reference that model directly, and a policy
     * lookup keyed on the stock row's own attributes doesn't need to.
     */
    private function policyFor(Model $record): ?WarehouseReplenishmentPolicy
    {
        $warehouseId = $record->getAttribute('warehouse_id');
        $productVariantId = $record->getAttribute('product_variant_id');

        if (! is_numeric($warehouseId) || ! is_numeric($productVariantId)) {
            return null;
        }

        return WarehouseReplenishmentPolicy::query()
            ->where('warehouse_id', (int) $warehouseId)
            ->where('product_variant_id', (int) $productVariantId)
            ->first();
    }

    private function isLowStock(Model $record): bool
    {
        $policy = $this->policyFor($record);
        $availableQuantity = $record->getAttribute('available_quantity');

        if (! $policy instanceof WarehouseReplenishmentPolicy || ! is_numeric($availableQuantity)) {
            return false;
        }

        return $policy->is_active && (float) $availableQuantity <= (float) $policy->min_quantity;
    }
}
