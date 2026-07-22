<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels\Tables;

use App\Models\InventoryStock;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class StockLevelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('productVariant.sku')
                    ->label(__('admin.inventory.stock.variant'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.name')
                    ->label(__('admin.inventory.stock.variant_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('warehouse.code')
                    ->label(__('admin.inventory.stock.warehouse'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('warehouse.name')
                    ->label(__('admin.inventory.stock.warehouse_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('on_hand_quantity')
                    ->label(__('admin.inventory.stock.on_hand_quantity'))
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('reserved_quantity')
                    ->label(__('admin.inventory.stock.reserved_quantity'))
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('available_quantity')
                    ->label(__('admin.inventory.stock.available_quantity'))
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('reorder_level')
                    ->label(__('admin.inventory.stock.reorder_level'))
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('low_stock')
                    ->label(__('admin.inventory.stock.low_stock'))
                    ->state(fn (InventoryStock $record): ?string => $record->isLowStock()
                        ? trans_choice('admin.inventory.stock.low_stock', 1)
                        : null)
                    ->badge()
                    ->color(fn (InventoryStock $record): string => $record->isLowStock() ? 'danger' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label(__('admin.inventory.stock.warehouse'))
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('low_stock')
                    ->label(__('admin.inventory.stock.low_stock'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('reorder_level')
                        ->whereColumn('available_quantity', '<=', 'reorder_level')),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
