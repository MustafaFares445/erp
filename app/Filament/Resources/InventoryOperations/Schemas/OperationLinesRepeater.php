<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Schemas;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;

final class OperationLinesRepeater
{
    public static function make(): Repeater
    {
        return Repeater::make('lines')
            ->relationship()
            ->columns(3)
            ->schema([
                Select::make('product_variant_id')
                    ->label(__('admin.inventory.operation.fields.product'))
                    ->relationship('productVariant', 'sku')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('quantity')
                    ->label(__('admin.inventory.operation.fields.demand'))
                    ->numeric()
                    ->minValue(0.001)
                    ->required(),
                Select::make('unit_id')
                    ->label(__('admin.inventory.operation.fields.unit'))
                    ->relationship('unit', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('warehouse_location_id')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload(),
                Select::make('package_id')
                    ->label(__('admin.inventory.operation.fields.package'))
                    ->relationship('package', 'name', fn (Builder $query): Builder => $query->where('is_active', true))
                    ->searchable()
                    ->preload(),
                Checkbox::make('is_picked')
                    ->label(__('admin.inventory.operation.fields.picked')),
            ])
            ->defaultItems(1)
            ->columnSpanFull();
    }
}
