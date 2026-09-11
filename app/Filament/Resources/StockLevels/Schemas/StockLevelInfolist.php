<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels\Schemas;

use App\Enums\StockCondition;
use App\Models\InventoryStock;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\ReplenishmentProjectionService;
use App\Services\Inventory\StockAvailabilityExplainer;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class StockLevelInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('productVariant.sku')->label(__('admin.inventory.stock.variant')),
                TextEntry::make('productVariant.name')->label(__('admin.inventory.stock.variant_name')),
                TextEntry::make('warehouse.code')->label(__('admin.inventory.stock.warehouse')),
                TextEntry::make('warehouse.name')->label(__('admin.inventory.stock.warehouse_name')),
                TextEntry::make('on_hand_quantity')
                    ->label(__('admin.inventory.stock.on_hand_quantity'))
                    ->numeric(decimalPlaces: 3),
                TextEntry::make('saleable_quantity')
                    ->label(__('admin.inventory.stock.saleable_quantity'))
                    ->state(fn (InventoryStock $record): float => $record->conditionOnHandQuantity(StockCondition::Saleable))
                    ->numeric(decimalPlaces: 3),
                TextEntry::make('quarantine_quantity')
                    ->label(__('admin.inventory.stock.quarantine_quantity'))
                    ->state(fn (InventoryStock $record): float => $record->conditionOnHandQuantity(StockCondition::Quarantine))
                    ->numeric(decimalPlaces: 3),
                TextEntry::make('reserved_quantity')
                    ->label(__('admin.inventory.stock.reserved_quantity'))
                    ->state(fn (InventoryStock $record): float => $record->conditionReservedQuantity(StockCondition::Saleable))
                    ->numeric(decimalPlaces: 3),
                TextEntry::make('damaged_quantity')
                    ->label(__('admin.inventory.stock.damaged_quantity'))
                    ->state(fn (InventoryStock $record): float => $record->conditionOnHandQuantity(StockCondition::Damaged))
                    ->numeric(decimalPlaces: 3),
                TextEntry::make('available_quantity')
                    ->label(__('admin.inventory.stock.available_quantity'))
                    ->state(fn (InventoryStock $record): float => $record->saleableAvailableQuantity())
                    ->numeric(decimalPlaces: 3),
                TextEntry::make('policy_minimum')
                    ->label('Min')
                    ->state(fn (InventoryStock $record): ?float => self::policy($record)?->min_quantity === null
                        ? null
                        : (float) self::policy($record)?->min_quantity)
                    ->numeric(decimalPlaces: 3)
                    ->placeholder('—'),
                TextEntry::make('policy_maximum')
                    ->label('Max')
                    ->state(fn (InventoryStock $record): ?float => self::policy($record)?->max_quantity === null
                        ? null
                        : (float) self::policy($record)?->max_quantity)
                    ->numeric(decimalPlaces: 3)
                    ->placeholder('—'),
                TextEntry::make('projected_stock')
                    ->label('Projected Stock')
                    ->state(function (InventoryStock $record): ?float {
                        $policy = self::policy($record);

                        return $policy instanceof WarehouseReplenishmentPolicy
                            ? app(ReplenishmentProjectionService::class)->project($policy)->projectedStock()
                            : null;
                    })
                    ->numeric(decimalPlaces: 3)
                    ->placeholder('—'),
            ]),
            Section::make(__('admin.inventory.stock.availability_breakdown'))
                ->schema([
                    ViewEntry::make('availability_breakdown')
                        ->hiddenLabel()
                        ->view('filament.inventory.stock-availability-breakdown')
                        ->viewData(fn (InventoryStock $record): array => [
                            'explanation' => app(StockAvailabilityExplainer::class)->explainStock($record),
                        ])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function policy(InventoryStock $stock): ?WarehouseReplenishmentPolicy
    {
        return WarehouseReplenishmentPolicy::query()
            ->where('warehouse_id', $stock->warehouse_id)
            ->where('product_variant_id', $stock->product_variant_id)
            ->first();
    }
}
