<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarehouseReplenishmentPolicies\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class WarehouseReplenishmentPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('warehouse_id')
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label(__('admin.inventory.replenishment.fields.warehouse'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.sku')
                    ->label(__('admin.inventory.replenishment.fields.product_variant'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('min_quantity')
                    ->label(__('admin.inventory.replenishment.fields.min_quantity'))
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                TextColumn::make('max_quantity')
                    ->label(__('admin.inventory.replenishment.fields.max_quantity'))
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('admin.inventory.replenishment.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label(__('admin.inventory.replenishment.fields.warehouse'))
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
