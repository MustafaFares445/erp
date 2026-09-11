<?php

declare(strict_types=1);

namespace App\Filament\Resources\Warehouses\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only per-warehouse stock state. Replenishment policy is intentionally
 * managed separately because desired Min/Max targets are not stock state.
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
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
