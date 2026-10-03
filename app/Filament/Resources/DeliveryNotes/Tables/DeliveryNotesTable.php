<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryNotes\Tables;

use App\Enums\OperationStage;
use App\Filament\Resources\DeliveryNotes\Actions\DeliveryNoteActions;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\InventoryOperation;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class DeliveryNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('invoiceDeliveryLink'))
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder(__('Search by delivery note number or customer name'))
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('operation_number')->label(__('admin.inventory.operation.fields.operation_number'))->placeholder(__('admin.inventory.adjustment.number_pending'))->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('admin.inventory.operation.fields.customer'))->searchable(),
                TextColumn::make('sourceWarehouse.name')->label(__('admin.inventory.operation.fields.source_warehouse'))->searchable(),
                TextColumn::make('scheduled_at')->label(__('admin.inventory.operation.fields.scheduled_at'))->dateTime()->sortable(),
                TextColumn::make('stage')->badge()->formatStateUsing(fn (OperationStage $state, InventoryOperation $record): string => $record->stageLabel())->color(fn (OperationStage $state): string => match ($state) {
                    OperationStage::Draft => 'gray', OperationStage::Waiting => 'warning', OperationStage::Ready => 'success', OperationStage::InTransit, OperationStage::PartiallyReceived => 'primary', OperationStage::Done => 'success', OperationStage::Canceled => 'danger',
                }),
                TextColumn::make('invoiced')
                    ->label(__('Invoiced'))
                    ->badge()
                    ->state(fn (InventoryOperation $record): string => $record->isInvoiced() ? 'Invoiced' : 'Uninvoiced')
                    ->color(fn (InventoryOperation $record): string => $record->isInvoiced() ? 'success' : 'gray'),
            ])
            ->groups([
                Group::make('stage')
                    ->label(__('Stage'))
                    ->getTitleFromRecordUsing(static fn (InventoryOperation $record): string => $record->stageLabel()),
                Group::make('customer.company_name')->label(__('admin.inventory.operation.fields.customer')),
                Group::make('sourceWarehouse.name')->label(__('admin.inventory.operation.fields.source_warehouse')),
                Group::make('scheduled_at')->label(__('admin.inventory.operation.fields.scheduled_at'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('stage')
                        ->label(__('Stage'))
                        ->options(static fn (): array => collect(OperationStage::cases())
                            ->mapWithKeys(static fn (OperationStage $stage): array => [$stage->value => $stage->label()])
                            ->all())
                        ->multiple(),
                    TextConstraint::make('operation_number')->label(__('admin.inventory.operation.fields.operation_number')),
                    RelationshipConstraint::make('customer')
                        ->label(__('admin.inventory.operation.fields.customer'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('company_name')->searchable()->multiple()),
                    RelationshipConstraint::make('sourceWarehouse')
                        ->label(__('admin.inventory.operation.fields.source_warehouse'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    DateConstraint::make('scheduled_at')->label(__('admin.inventory.operation.fields.scheduled_at')),
                    DateConstraint::make('completed_at')->label(__('Completed at')),
                    DateConstraint::make('created_at')->label(__('Created at')),
                ]),
                Filter::make('uninvoiced')
                    ->label(__('Uninvoiced'))
                    ->query(fn (Builder $query): Builder => $query
                        ->where('stage', OperationStage::Done->value)
                        ->whereDoesntHave('invoiceDeliveryLink')),
            ])
            ->recordActions([
                DeliveryNoteActions::createInvoice()->button(),
                ViewAction::make(),
            ]);
    }
}
