<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels\Schemas;

use App\Enums\StockCondition;
use App\Models\InventoryStock;
use Filament\Infolists\Components\TextEntry;
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
                TextEntry::make('reorder_level')->numeric(decimalPlaces: 3),
            ]),
        ]);
    }
}
