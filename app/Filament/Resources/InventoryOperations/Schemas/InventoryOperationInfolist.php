<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Schemas;

use App\Enums\DeliveryDocument;
use App\Enums\DeliveryType;
use App\Enums\OperationType;
use App\Models\InventoryOperation;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
            Section::make(__('admin.sections.operations'))->schema([
                RepeatableEntry::make('lines')->label('')->columns(4)->schema([
                    TextEntry::make('productVariant.sku')->label(__('admin.inventory.operation.fields.product')),
                    TextEntry::make('quantity')->label(__('admin.inventory.operation.fields.demand')),
                    TextEntry::make('unit.name')->label(__('admin.inventory.operation.fields.unit')),
                    TextEntry::make('is_picked')->label(__('admin.inventory.operation.fields.picked'))->badge(),
                ]),
            ]),
            Section::make(__('admin.inventory.operation.sections.delivery_documents'))
                ->visible(fn (InventoryOperation $record): bool => $record->operation_type === OperationType::Delivery)
                ->schema([
                    TextEntry::make('delivery_document_status')
                        ->label(__('admin.inventory.operation.fields.delivery_documents'))
                        ->state(fn (InventoryOperation $record): string => $record->hasCompleteDeliveryDocuments()
                            ? __('admin.inventory.operation.documents_complete')
                            : __('admin.inventory.operation.documents_missing', ['documents' => implode(', ', array_map(static fn (DeliveryDocument $document): string => $document->label(), $record->missingDeliveryDocuments()))]))
                        ->badge()
                        ->color(fn (InventoryOperation $record): string => $record->hasCompleteDeliveryDocuments() ? 'success' : 'warning')
                        ->columnSpanFull(),
                    ...array_map(self::deliveryDocumentEntry(...), DeliveryDocument::cases()),
                ])
                ->columns(2),
        ]);
    }

    private static function deliveryDocumentEntry(DeliveryDocument $document): TextEntry
    {
        return TextEntry::make($document->value)
            ->label($document->label())
            ->state(function (InventoryOperation $record) use ($document): string {
                $media = $record->getFirstMedia($document->value);

                return $media instanceof Media ? $media->file_name : __('admin.inventory.operation.document_missing');
            })
            ->url(fn (InventoryOperation $record): ?string => self::mediaRoute($record, $record->getFirstMedia($document->value), 'preview'))
            ->openUrlInNewTab()
            ->suffixAction(
                Action::make('download_'.$document->value)
                    ->label(__('admin.inventory.operation.download'))
                    ->icon(Heroicon::ArrowDownTray)
                    ->url(fn (InventoryOperation $record): ?string => self::mediaRoute($record, $record->getFirstMedia($document->value), 'download'))
                    ->openUrlInNewTab()
                    ->visible(fn (InventoryOperation $record): bool => $record->getFirstMedia($document->value) instanceof Media),
            )
            ->color(fn (InventoryOperation $record): string => $record->getFirstMedia($document->value) instanceof Media ? 'success' : 'warning');
    }

    private static function mediaRoute(InventoryOperation $record, ?Media $media, string $action): ?string
    {
        return $media instanceof Media
            ? route('admin.inventory-operations.media.'.$action, ['operation' => $record, 'media' => $media])
            : null;
    }
}
