<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarehouseReplenishmentPolicies\Tables;

use App\Data\Inventory\ReplenishmentRecommendation;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\ReplenishmentRecommendationService;
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
                TextColumn::make('available')
                    ->label(__('Available'))
                    ->state(fn (WarehouseReplenishmentPolicy $record): float => self::recommendation($record)->available)
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('reserved')
                    ->label(__('Reserved'))
                    ->state(fn (WarehouseReplenishmentPolicy $record): float => self::recommendation($record)->reserved)
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('incoming')
                    ->label(__('Incoming'))
                    ->state(fn (WarehouseReplenishmentPolicy $record): float => self::recommendation($record)->incoming)
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('min_quantity')
                    ->label(__('admin.inventory.replenishment.fields.min_quantity'))
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                TextColumn::make('max_quantity')
                    ->label(__('admin.inventory.replenishment.fields.max_quantity'))
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                TextColumn::make('suggested_quantity')
                    ->label(__('Suggested qty'))
                    ->state(fn (WarehouseReplenishmentPolicy $record): float => self::recommendation($record)->suggestedBaseQuantity)
                    ->numeric(decimalPlaces: 3)
                    ->badge()
                    ->color(fn (WarehouseReplenishmentPolicy $record): string => self::recommendation($record)->needsPurchase() ? 'warning' : 'success'),
                TextColumn::make('supplier')
                    ->label(__('Supplier'))
                    ->state(fn (WarehouseReplenishmentPolicy $record): ?string => self::recommendation($record)->supplierName)
                    ->placeholder(__('No eligible supplier')),
                TextColumn::make('lead_time')
                    ->label(__('Lead time'))
                    ->state(fn (WarehouseReplenishmentPolicy $record): ?int => self::recommendation($record)->leadTimeDays)
                    ->suffix(__(' days'))
                    ->placeholder(__('—')),
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

    private static function recommendation(WarehouseReplenishmentPolicy $policy): ReplenishmentRecommendation
    {
        return app(ReplenishmentRecommendationService::class)->recommendation($policy);
    }
}
