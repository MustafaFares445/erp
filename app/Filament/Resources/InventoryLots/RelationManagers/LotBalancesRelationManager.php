<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryLots\RelationManagers;

use App\Models\InventoryLotBalance;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class LotBalancesRelationManager extends RelationManager
{
    protected static string $relationship = 'conditionBalances';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('warehouse.code')->label(__('admin.inventory.lot.fields.warehouse'))->sortable(),
                TextColumn::make('stock_condition')->label(__('admin.inventory.lot.fields.condition'))->badge()->sortable(),
                TextColumn::make('on_hand_base_quantity')->label(__('admin.inventory.lot.fields.on_hand'))->numeric(decimalPlaces: 6),
                TextColumn::make('reserved_base_quantity')->label(__('admin.inventory.lot.fields.reserved'))->numeric(decimalPlaces: 6),
                TextColumn::make('available')
                    ->label(__('admin.inventory.lot.fields.available'))
                    ->state(fn (Model $record): string => $record instanceof InventoryLotBalance
                        ? $record->availableBaseQuantity()
                        : '0.000000')
                    ->numeric(decimalPlaces: 6),
            ])
            ->defaultSort('warehouse_id')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
