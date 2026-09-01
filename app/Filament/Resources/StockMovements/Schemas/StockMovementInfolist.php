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
                        TextEntry::make('transaction_quantity')
                            ->label(__('admin.inventory.movement.transaction_quantity'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder('—'),
                        TextEntry::make('transactionUnit.symbol')
                            ->label(__('admin.inventory.movement.transaction_unit'))
                            ->placeholder('—'),
                        TextEntry::make('conversion_factor_snapshot')
                            ->label(__('admin.inventory.movement.conversion_factor'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder('—'),
                        TextEntry::make('base_quantity_delta')
                            ->label(__('admin.inventory.movement.base_quantity_delta'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder('—'),
                        TextEntry::make('quantity')
                            ->label(__('admin.inventory.movement.legacy_quantity'))
                            ->placeholder('—'),
                        TextEntry::make('lot.lot_number')
                            ->label(__('admin.inventory.movement.lot'))
                            ->placeholder('—'),
                        TextEntry::make('serializedUnit.serial_number')
                            ->label(__('admin.inventory.movement.serial'))
                            ->placeholder('—'),
                        TextEntry::make('package.name')
                            ->label(__('admin.inventory.movement.package'))
                            ->placeholder('—'),
                        TextEntry::make('stock_condition_from')
                            ->label(__('admin.inventory.movement.condition_from'))
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('stock_condition_to')
                            ->label(__('admin.inventory.movement.condition_to'))
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('condition_from_on_hand_before')
                            ->label(__('admin.inventory.movement.condition_from_before'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder('—'),
                        TextEntry::make('condition_from_on_hand_after')
                            ->label(__('admin.inventory.movement.condition_from_after'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder('—'),
                        TextEntry::make('condition_from_reserved_before')
                            ->label(__('admin.inventory.movement.condition_from_reserved_before'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder('—'),
                        TextEntry::make('condition_from_reserved_after')
                            ->label(__('admin.inventory.movement.condition_from_reserved_after'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder('—'),
                        TextEntry::make('condition_to_on_hand_before')
                            ->label(__('admin.inventory.movement.condition_to_before'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder('—'),
                        TextEntry::make('condition_to_on_hand_after')
                            ->label(__('admin.inventory.movement.condition_to_after'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder('—'),
                        TextEntry::make('condition_to_reserved_before')
                            ->label(__('admin.inventory.movement.condition_to_reserved_before'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder('—'),
                        TextEntry::make('condition_to_reserved_after')
                            ->label(__('admin.inventory.movement.condition_to_reserved_after'))
                            ->numeric(decimalPlaces: 6)
                            ->placeholder('—'),
                        TextEntry::make('source_line_reference')
                            ->label(__('admin.inventory.movement.source_line'))
                            ->state(fn (InventoryMovement $record): ?string => $record->source_line_type === null
                                ? null
                                : sprintf('%s #%s', $record->source_line_type, $record->source_line_id ?? '—'))
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('createdBy.name')
                            ->label(__('admin.inventory.movement.creator'))
                            ->default(__('admin.inventory.movement.system')),
                        TextEntry::make('source_reference')
                            ->label(__('admin.inventory.movement.source'))
                            ->state(fn (InventoryMovement $record): string => StockMovementsTable::sourceReference($record))
                            ->url(fn (InventoryMovement $record): ?string => StockMovementsTable::sourceUrl($record)),
                        TextEntry::make('reversal_reference')
                            ->label(__('admin.inventory.movement.reversal_of'))
                            ->state(fn (InventoryMovement $record): ?string => $record->reversal_of_movement_id === null
                                ? null
                                : '#'.$record->reversal_of_movement_id)
                            ->url(fn (InventoryMovement $record): ?string => StockMovementsTable::reversalUrl($record))
                            ->placeholder('—'),
                        TextEntry::make('notes')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
