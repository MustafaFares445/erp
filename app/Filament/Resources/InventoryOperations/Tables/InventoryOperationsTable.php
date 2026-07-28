<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Tables;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\InventoryOperation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class InventoryOperationsTable
{
    public static function configure(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('operation_number')->label(__('admin.inventory.operation.fields.operation_number'))->placeholder(__('admin.inventory.adjustment.number_pending'))->searchable()->sortable(),
            TextColumn::make('supplier.name')->label(__('admin.inventory.operation.fields.supplier'))->searchable(),
            TextColumn::make('scheduled_at')->label(__('admin.inventory.operation.fields.scheduled_at'))->dateTime()->sortable(),
            TextColumn::make('source_document_type')->label(__('admin.inventory.operation.fields.source_document'))->placeholder('—'),
            TextColumn::make('stage')->badge()->formatStateUsing(fn (OperationStage $stage): string => $stage->label())->color(fn (OperationStage $stage): string => match ($stage) {
                OperationStage::Draft => 'gray', OperationStage::Waiting => 'warning', OperationStage::Ready => 'info', OperationStage::InTransit => 'primary', OperationStage::Done => 'success', OperationStage::Canceled => 'danger',
            }),
        ])
            ->filters([
                SelectFilter::make('operation_type')->options(collect(OperationType::cases())->mapWithKeys(fn (OperationType $type): array => [$type->value => $type->label()])->all()),
                SelectFilter::make('stage')->options(collect(OperationStage::cases())->mapWithKeys(fn (OperationStage $stage): array => [$stage->value => $stage->label()])->all()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (InventoryOperation $record): bool => $record->isDraft()),
                DeleteAction::make()->visible(fn (InventoryOperation $record): bool => $record->isDraft()),
            ]);
    }
}
