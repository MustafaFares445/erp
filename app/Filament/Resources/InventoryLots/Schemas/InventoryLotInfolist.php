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
                Section::make()->columns(2)->schema([
                    TextEntry::make('lot_number')->label('Lot')->placeholder('—'),
                    TextEntry::make('productVariant.sku')->label('SKU'),
                    TextEntry::make('productVariant.product.name')->label('Product'),
                    TextEntry::make('warehouse.code')->label('Warehouse'),
                    TextEntry::make('expires_at')->date(),
                    TextEntry::make('days_remaining')
                        ->state(fn (InventoryLot $record): ?int => $record->daysRemaining()),
                    TextEntry::make('on_hand_quantity')
                        ->label(__('admin.inventory.stock.on_hand_quantity'))
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('saleable_quantity')
                        ->label(__('admin.inventory.stock.saleable_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->conditionOnHandQuantity(StockCondition::Saleable))
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('quarantine_quantity')
                        ->label(__('admin.inventory.stock.quarantine_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->conditionOnHandQuantity(StockCondition::Quarantine))
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('damaged_quantity')
                        ->label(__('admin.inventory.stock.damaged_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->conditionOnHandQuantity(StockCondition::Damaged))
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('reserved_quantity')
                        ->label(__('admin.inventory.stock.reserved_quantity'))
                        ->state(fn (InventoryLot $record): float => $record->conditionReservedQuantity(StockCondition::Saleable))
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('available_quantity')
                        ->state(fn (InventoryLot $record): float => $record->availableQuantity())
                        ->numeric(decimalPlaces: 3),
                    TextEntry::make('expiry_state')
                        ->state(fn (InventoryLot $record): string => $record->expiryState())
                        ->badge(),
                    TextEntry::make('receiptItem.receipt.receipt_number')->label('Receipt')->placeholder('—'),
                ]),
            ]);
    }
}
