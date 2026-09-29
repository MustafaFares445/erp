<?php

declare(strict_types=1);

namespace App\Filament\Resources\SerializedInventoryUnits\Tables;

use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
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
                TextColumn::make('serial_number')->label(__('admin.inventory.serialized_unit.fields.serial'))->searchable()->sortable(),
                TextColumn::make('iot_number')->label(__('admin.inventory.serialized_unit.fields.iot'))->searchable()->placeholder('—'),
                TextColumn::make('productVariant.sku')->label(__('admin.inventory.serialized_unit.fields.sku'))->searchable()->sortable(),
                TextColumn::make('productVariant.product.name')->label(__('admin.inventory.serialized_unit.fields.product'))->searchable()->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (SerializedInventoryUnitStatus $state): string => __('admin.inventory.serialized_unit.statuses.'.$state->value))->sortable(),
                TextColumn::make('stock_condition')->label(__('admin.inventory.serialized_unit.fields.condition'))->badge()->formatStateUsing(fn (StockCondition $state): string => __('admin.inventory.serialized_unit.conditions.'.$state->value))->sortable(),
                TextColumn::make('custody_type')->label(__('admin.inventory.serialized_unit.fields.custody'))->badge()->formatStateUsing(fn (SerializedCustodyType $state): string => __('admin.inventory.serialized_unit.custody.'.$state->value))->sortable(),
                TextColumn::make('warehouse.code')->label(__('admin.inventory.serialized_unit.fields.current_warehouse'))->searchable()->sortable()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options(collect(SerializedInventoryUnitStatus::cases())
                        ->mapWithKeys(fn (SerializedInventoryUnitStatus $status): array => [$status->value => __('admin.inventory.serialized_unit.statuses.'.$status->value)])
                        ->all()),
                SelectFilter::make('stock_condition')
                    ->label(__('admin.inventory.serialized_unit.fields.condition'))
                    ->options(collect(StockCondition::cases())
                        ->mapWithKeys(fn (StockCondition $condition): array => [$condition->value => __('admin.inventory.serialized_unit.conditions.'.$condition->value)])
                        ->all()),
                SelectFilter::make('custody_type')
                    ->label(__('admin.inventory.serialized_unit.fields.custody'))
                    ->options(collect(SerializedCustodyType::cases())
                        ->mapWithKeys(fn (SerializedCustodyType $custody): array => [$custody->value => __('admin.inventory.serialized_unit.custody.'.$custody->value)])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
