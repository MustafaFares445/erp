<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryNotes\Tables;

use App\Enums\OperationStage;
use App\Models\InventoryOperation;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class DeliveryNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('operation_number')->label(__('admin.inventory.operation.fields.operation_number'))->placeholder(__('admin.inventory.adjustment.number_pending'))->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('admin.inventory.operation.fields.customer'))->searchable(),
                TextColumn::make('sourceWarehouse.name')->label(__('admin.inventory.operation.fields.source_warehouse'))->searchable(),
                TextColumn::make('scheduled_at')->label(__('admin.inventory.operation.fields.scheduled_at'))->dateTime()->sortable(),
                TextColumn::make('stage')->badge()->formatStateUsing(fn (OperationStage $state, InventoryOperation $record): string => $record->stageLabel()),
                TextColumn::make('invoiced')
                    ->label('Invoiced')
                    ->badge()
                    ->state(fn (InventoryOperation $record): string => $record->isInvoiced() ? 'Invoiced' : 'Uninvoiced')
                    ->color(fn (InventoryOperation $record): string => $record->isInvoiced() ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('stage')->options(collect(OperationStage::cases())
                    ->mapWithKeys(fn (OperationStage $stage): array => [$stage->value => $stage->label()])
                    ->all()),
                Filter::make('uninvoiced')
                    ->label('Uninvoiced')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('stage', OperationStage::Done->value)
                        ->whereDoesntHave('invoiceDeliveryLink')),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
