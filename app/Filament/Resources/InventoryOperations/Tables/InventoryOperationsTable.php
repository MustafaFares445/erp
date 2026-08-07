<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Tables;

use App\Enums\DeliveryDocument;
use App\Enums\DeliveryType;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryOperation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class InventoryOperationsTable
{
    public static function configure(Table $table): Table
    {
        $operationType = InventoryOperationResource::currentOperationType();

        return $table
            ->defaultSort('created_at', 'desc')->columns([
                TextColumn::make('operation_number')->label(__('admin.inventory.operation.fields.operation_number'))->placeholder(__('admin.inventory.adjustment.number_pending'))->searchable()->sortable(),
                TextColumn::make('operation_type')
                    ->label(__('admin.inventory.operation.fields.operation_type'))
                    ->formatStateUsing(fn (OperationType $state): string => $state->label())
                    ->visible(! $operationType instanceof OperationType),
                TextColumn::make('supplier.name')
                    ->label(__('admin.inventory.operation.fields.supplier'))
                    ->searchable()
                    ->visible(! $operationType instanceof OperationType || $operationType === OperationType::Receipt),
                TextColumn::make('customer.company_name')
                    ->label(__('admin.inventory.operation.fields.customer'))
                    ->searchable()
                    ->placeholder(__('admin.inventory.operation.placeholders.no_customer'))
                    ->visible(! $operationType instanceof OperationType || $operationType === OperationType::Delivery),
                TextColumn::make('delivery_type')
                    ->label(__('admin.inventory.operation.fields.delivery_type'))
                    ->formatStateUsing(fn (?DeliveryType $state): ?string => $state?->label())
                    ->badge()
                    ->visible(! $operationType instanceof OperationType || $operationType === OperationType::Delivery),
                TextColumn::make('sourceWarehouse.name')
                    ->label(__('admin.inventory.operation.fields.source_warehouse'))
                    ->searchable()
                    ->visible(in_array($operationType, [null, OperationType::Delivery, OperationType::InternalTransfer], true)),
                TextColumn::make('destinationWarehouse.name')
                    ->label(__('admin.inventory.operation.fields.destination_warehouse'))
                    ->searchable()
                    ->visible(in_array($operationType, [null, OperationType::Receipt, OperationType::InternalTransfer], true)),
                TextColumn::make('scheduled_at')->label(__('admin.inventory.operation.fields.scheduled_at'))->dateTime()->sortable(),
                TextColumn::make('delivery_documents')
                    ->label(__('admin.inventory.operation.fields.delivery_documents'))
                    ->state(fn (InventoryOperation $record): string => $record->hasCompleteDeliveryDocuments()
                        ? __('admin.inventory.operation.documents_complete')
                        : __('admin.inventory.operation.documents_missing_count', ['count' => count($record->missingDeliveryDocuments())]))
                    ->badge()
                    ->color(fn (InventoryOperation $record): string => $record->hasCompleteDeliveryDocuments() ? 'success' : 'warning')
                    ->icon(fn (InventoryOperation $record): Heroicon => $record->hasCompleteDeliveryDocuments() ? Heroicon::CheckCircle : Heroicon::ExclamationTriangle)
                    ->tooltip(fn (InventoryOperation $record): ?string => $record->hasCompleteDeliveryDocuments()
                        ? null
                        : implode(', ', array_map(static fn (DeliveryDocument $document): string => $document->label(), $record->missingDeliveryDocuments())))
                    ->visible(! $operationType instanceof OperationType || $operationType === OperationType::Delivery),
                TextColumn::make('stage')->badge()->formatStateUsing(fn (OperationStage $state, InventoryOperation $record): string => $record->stageLabel())->color(fn (OperationStage $state): string => match ($state) {
                    OperationStage::Draft => 'gray', OperationStage::Waiting => 'warning', OperationStage::Ready => 'info', OperationStage::InTransit => 'primary', OperationStage::Done => 'success', OperationStage::Canceled => 'danger',
                }),
            ])
            ->filters([
                SelectFilter::make('operation_type')->options(collect(OperationType::cases())->mapWithKeys(fn (OperationType $type): array => [$type->value => $type->label()])->all()),
                SelectFilter::make('stage')->options(collect(OperationStage::cases())->mapWithKeys(fn (OperationStage $stage): array => [$stage->value => $stage === OperationStage::Done && $operationType === OperationType::Delivery
                    ? __('admin.inventory.operation.stages.delivered')
                    : $stage->label()])->all()),
                Filter::make('missing_delivery_documents')
                    ->label(__('admin.inventory.operation.filters.missing_delivery_documents'))
                    ->toggle()
                    ->visible(! $operationType instanceof OperationType || $operationType === OperationType::Delivery)
                    ->query(fn (Builder $query): Builder => $query
                        ->where('operation_type', OperationType::Delivery->value)
                        ->whereHas('media', fn (Builder $mediaQuery): Builder => $mediaQuery->whereIn(
                            'collection_name',
                            array_map(static fn (DeliveryDocument $document): string => $document->value, DeliveryDocument::cases()),
                        ), '<', count(DeliveryDocument::cases()))),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (InventoryOperation $record): bool => $record->isDraft()),
                DeleteAction::make()->visible(fn (InventoryOperation $record): bool => $record->isDraft()),
            ]);
    }
}
