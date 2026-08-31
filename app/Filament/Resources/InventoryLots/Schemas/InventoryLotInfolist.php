<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryLots\Schemas;

use App\Enums\StockCondition;
use App\Models\InventoryLot;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class InventoryLotInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Lot identity')->columns(2)->schema([
                    TextEntry::make('lot_number')->label('Lot')->placeholder('—'),
                    TextEntry::make('normalized_lot_number')->label('Normalized lot')->placeholder('—'),
                    TextEntry::make('productVariant.sku')->label('SKU'),
                    TextEntry::make('productVariant.product.name')->label('Product'),
                    TextEntry::make('expires_at')->date()->placeholder('—'),
                    TextEntry::make('days_remaining')
                        ->state(fn (InventoryLot $record): ?int => $record->daysRemaining()),
                    TextEntry::make('origin_source_type')->label('Origin')->placeholder('—'),
                    TextEntry::make('origin_source_id')->label('Origin ID')->placeholder('—'),
                    TextEntry::make('expiry_state')
                        ->state(fn (InventoryLot $record): string => $record->expiryState())
                        ->badge(),
                ]),
                Section::make('Current balances')->columns(3)->schema([
                    TextEntry::make('total_physical')
                        ->label(__('admin.inventory.stock.on_hand_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->totalPhysicalQuantity())
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('saleable_quantity')
                        ->label(__('admin.inventory.stock.saleable_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->totalConditionOnHandQuantity(StockCondition::Saleable))
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('quarantine_quantity')
                        ->label(__('admin.inventory.stock.quarantine_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->totalConditionOnHandQuantity(StockCondition::Quarantine))
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('damaged_quantity')
                        ->label(__('admin.inventory.stock.damaged_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->totalConditionOnHandQuantity(StockCondition::Damaged))
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('reserved_quantity')
                        ->label(__('admin.inventory.stock.reserved_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->totalConditionReservedQuantity(StockCondition::Saleable))
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('available_quantity')
                        ->state(fn (InventoryLot $record): float => $record->totalAvailableQuantity())
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('warehouse_count')
                        ->label('Warehouses')
                        ->state(fn (InventoryLot $record): int => $record->warehouseCount()),
                ]),
            ]);
    }
}
