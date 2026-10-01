<?php

declare(strict_types=1);

namespace App\Filament\Resources\Warehouses\Schemas;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\Warehouse;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class WarehouseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.inventory.warehouse.sections.identity'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('code')->label(__('admin.inventory.warehouse.fields.code')),
                        TextEntry::make('name')->label(__('admin.inventory.warehouse.fields.name')),
                        IconEntry::make('is_active')->label(__('admin.inventory.warehouse.is_active'))->boolean(),
                        TextEntry::make('address')->label(__('admin.inventory.warehouse.fields.address'))->columnSpan(2),
                        TextEntry::make('created_at')->label(__('admin.inventory.warehouse.fields.created_at'))->dateTime(),
                        TextEntry::make('latitude')->label(__('admin.inventory.warehouse.fields.latitude'))->placeholder(__('—')),
                        TextEntry::make('longitude')->label(__('admin.inventory.warehouse.fields.longitude'))->placeholder(__('—')),
                    ]),
                Section::make(__('admin.inventory.warehouse.sections.operations'))
                    ->columns(4)
                    ->schema([
                        TextEntry::make('stock_skus')
                            ->label(__('admin.inventory.warehouse.fields.stock_skus'))
                            ->state(fn (Warehouse $record): int => $record->stocks()->where('on_hand_quantity', '>', 0)->count()),
                        TextEntry::make('on_hand_total')
                            ->label(__('admin.inventory.warehouse.fields.on_hand'))
                            ->state(fn (Warehouse $record): float => (float) $record->stocks()->sum('on_hand_quantity'))
                            ->numeric(decimalPlaces: 3),
                        TextEntry::make('reserved_total')
                            ->label(__('admin.inventory.warehouse.fields.reserved'))
                            ->state(fn (Warehouse $record): float => (float) $record->stocks()->sum('reserved_quantity'))
                            ->numeric(decimalPlaces: 3),
                        TextEntry::make('available_total')
                            ->label(__('admin.inventory.warehouse.fields.available'))
                            ->state(fn (Warehouse $record): float => (float) $record->stocks()->sum('available_quantity'))
                            ->numeric(decimalPlaces: 3),
                        TextEntry::make('incoming_receipts')
                            ->label(__('admin.inventory.warehouse.fields.incoming_receipts'))
                            ->state(fn (Warehouse $record): int => $record->destinationOperations()
                                ->where('operation_type', OperationType::Receipt->value)
                                ->whereNotIn('stage', [OperationStage::Done->value, OperationStage::Canceled->value])
                                ->count()),
                        TextEntry::make('ready_deliveries')
                            ->label(__('admin.inventory.warehouse.fields.ready_deliveries'))
                            ->state(fn (Warehouse $record): int => $record->sourceOperations()
                                ->where('operation_type', OperationType::Delivery->value)
                                ->where('stage', OperationStage::Ready->value)
                                ->count()),
                        TextEntry::make('transfers_in_transit')
                            ->label(__('admin.inventory.warehouse.fields.transfers_in_transit'))
                            ->state(fn (Warehouse $record): int => $record->destinationOperations()
                                ->where('operation_type', OperationType::InternalTransfer->value)
                                ->whereIn('stage', [OperationStage::InTransit->value, OperationStage::PartiallyReceived->value])
                                ->count()),
                    ]),
            ]);
    }
}
