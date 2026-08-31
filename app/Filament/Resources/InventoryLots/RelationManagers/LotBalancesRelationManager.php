<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryLots\RelationManagers;

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
                TextColumn::make('warehouse.code')->label('Warehouse')->sortable(),
                TextColumn::make('stock_condition')->label('Condition')->badge()->sortable(),
                TextColumn::make('on_hand_base_quantity')->label('On hand')->numeric(decimalPlaces: 6),
                TextColumn::make('reserved_base_quantity')->label('Reserved')->numeric(decimalPlaces: 6),
                TextColumn::make('available')
                    ->label('Available')
                    ->state(fn (Model $record): string => method_exists($record, 'availableBaseQuantity')
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
