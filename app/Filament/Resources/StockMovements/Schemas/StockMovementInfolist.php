<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\InventoryMovement;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class StockMovementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')
                            ->label(__('admin.inventory.movement.date'))
                            ->dateTime(),
                        TextEntry::make('movement_type')
                            ->label(__('admin.inventory.movement.type'))
                            ->badge(),
                        TextEntry::make('productVariant.sku')
                            ->label(__('admin.inventory.stock.variant')),
                        TextEntry::make('productVariant.name')
                            ->label(__('admin.inventory.stock.variant_name')),
                        TextEntry::make('warehouse.code')
                            ->label(__('admin.inventory.stock.warehouse')),
                        TextEntry::make('warehouse.name')
                            ->label(__('admin.inventory.stock.warehouse_name')),
                        TextEntry::make('quantity')
                            ->label(__('admin.inventory.movement.quantity')),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('createdBy.name')
                            ->label(__('admin.inventory.movement.creator'))
                            ->default(__('admin.inventory.movement.system')),
                        TextEntry::make('source_reference')
                            ->label(__('admin.inventory.movement.source'))
                            ->state(fn (InventoryMovement $record): string => StockMovementsTable::sourceReference($record))
                            ->url(fn (InventoryMovement $record): ?string => StockMovementsTable::sourceUrl($record)),
                        TextEntry::make('notes')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
