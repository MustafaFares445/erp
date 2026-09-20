<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Schemas;

use App\Enums\DeliveryType;
use App\Enums\OperationType;
use App\Enums\TransferDiscrepancyDisposition;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class InventoryOperationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('operation_number')->label(__('admin.inventory.operation.fields.operation_number'))->placeholder(__('admin.inventory.adjustment.number_pending')),
                TextEntry::make('stage')->badge()->formatStateUsing(fn (mixed $state, InventoryOperation $record): string => $record->stageLabel()),
                TextEntry::make('sourceWarehouse.name')->label(__('admin.inventory.operation.fields.source_warehouse')),
                TextEntry::make('destinationWarehouse.name')->label(__('admin.inventory.operation.fields.destination_warehouse')),
                TextEntry::make('supplier.name')->label(__('admin.inventory.operation.fields.supplier')),
                TextEntry::make('customer.company_name')
                    ->label(__('admin.inventory.operation.fields.customer'))
                    ->visible(fn (InventoryOperation $record): bool => $record->operation_type === OperationType::Delivery),
                TextEntry::make('delivery_type')
                    ->label(__('admin.inventory.operation.fields.delivery_type'))
                    ->formatStateUsing(fn (?DeliveryType $state): ?string => $state?->label())
                    ->badge()
                    ->visible(fn (InventoryOperation $record): bool => $record->operation_type === OperationType::Delivery),
                TextEntry::make('scheduled_at')->label(__('admin.inventory.operation.fields.scheduled_at'))->dateTime(),
                TextEntry::make('notes')->label(__('admin.inventory.operation.fields.notes'))->columnSpanFull(),
            ]),
            Section::make(__('admin.sections.operations'))->gridContainer()->schema([
                RepeatableEntry::make('lines')->label('')->columns([
                    'default' => 1,
                    '@sm' => 2,
                    '@lg' => 4,
                ])->schema([
                    TextEntry::make('productVariant.sku')->label(__('admin.inventory.operation.fields.product')),
                    TextEntry::make('quantity')->label(__('admin.inventory.operation.fields.demand')),
                    TextEntry::make('unit.name')->label(__('admin.inventory.operation.fields.unit')),
                    TextEntry::make('is_picked')->label(__('admin.inventory.operation.fields.picked'))->badge(),
                    TextEntry::make('dispatched_base_quantity')
                        ->label(__('admin.inventory.operation.fields.dispatched_quantity'))
                        ->visible(fn (InventoryOperationLine $record): bool => $record->operation?->operation_type === OperationType::InternalTransfer),
                    TextEntry::make('received_base_quantity')
                        ->label(__('admin.inventory.operation.fields.received_quantity'))
                        ->visible(fn (InventoryOperationLine $record): bool => $record->operation?->operation_type === OperationType::InternalTransfer),
                    TextEntry::make('discrepancy_disposition')
                        ->label(__('admin.inventory.operation.fields.discrepancy_disposition'))
                        ->formatStateUsing(fn (?TransferDiscrepancyDisposition $state): ?string => $state?->name)
                        ->visible(fn (InventoryOperationLine $record): bool => $record->operation?->operation_type === OperationType::InternalTransfer),
                ]),
            ]),
            Section::make(__('admin.inventory.operation.sections.related_documents'))
                ->visible(fn (InventoryOperation $record): bool => $record->operation_type === OperationType::Delivery)
                ->schema(DeliveryRelatedDocuments::make())
                ->gridContainer()
                ->columns([
                    'default' => 1,
                    '@lg' => 2,
                ]),
        ]);
    }
}
