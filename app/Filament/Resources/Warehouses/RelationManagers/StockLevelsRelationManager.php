<?php

declare(strict_types=1);

namespace App\Filament\Resources\Warehouses\RelationManagers;

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
                TextColumn::make('reorder_level'),
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

    private function isLowStock(Model $record): bool
    {
        $reorderLevel = $record->getAttribute('reorder_level');
        $availableQuantity = $record->getAttribute('available_quantity');

        if (! is_numeric($reorderLevel) || ! is_numeric($availableQuantity)) {
            return false;
        }

        return (float) $availableQuantity <= (float) $reorderLevel;
    }
}
