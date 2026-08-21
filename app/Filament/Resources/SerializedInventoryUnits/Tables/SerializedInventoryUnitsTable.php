<?php

declare(strict_types=1);

namespace App\Filament\Resources\SerializedInventoryUnits\Tables;

use App\Enums\SerializedInventoryUnitStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class SerializedInventoryUnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('serial_number')->label('Serial')->searchable()->sortable(),
                TextColumn::make('iot_number')->label('IoT')->searchable()->placeholder('—'),
                TextColumn::make('productVariant.sku')->label('SKU')->searchable()->sortable(),
                TextColumn::make('productVariant.product.name')->label('Product')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('warehouse.code')->label('Warehouse')->searchable()->sortable()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options(collect(SerializedInventoryUnitStatus::cases())
                        ->mapWithKeys(fn (SerializedInventoryUnitStatus $status): array => [$status->value => $status->name])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
