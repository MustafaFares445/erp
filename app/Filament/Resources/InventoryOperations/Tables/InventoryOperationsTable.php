<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Tables;

use App\Enums\DeliveryType;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\InventoryOperation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\Constraint;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class InventoryOperationsTable
{
    /** @return array<string, Tab> */
    public static function presetTabs(): array
    {
        $openStages = array_map(
            static fn (OperationStage $stage): string => $stage->value,
            array_values(array_filter(
                OperationStage::cases(),
                static fn (OperationStage $stage): bool => ! $stage->isTerminal(),
            )),
        );

        return [
            'all' => Tab::make(__('Default'))->icon(Heroicon::OutlinedQueueList),
            'mine' => Tab::make(__('Assigned to me'))
                ->icon(Heroicon::OutlinedUser)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('responsible_id', auth()->id())),
            'open' => Tab::make(__('Open'))
                ->icon(Heroicon::OutlinedClock)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereIn('stage', $openStages)),
            'ready' => Tab::make(__('Ready'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('stage', OperationStage::Ready->value)),
            'done' => Tab::make(__('Done'))
                ->icon(Heroicon::OutlinedFlag)
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('stage', OperationStage::Done->value)),
        ];
    }

    public static function configure(Table $table): Table
    {
        $operationType = InventoryOperationResource::currentOperationType();

        return $table
            ->defaultSort('created_at', 'desc')->columns([
                FavoriteColumn::make(),
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
                TextColumn::make('stage')->label(__('Stage'))->badge()->formatStateUsing(fn (OperationStage $state, InventoryOperation $record): string => $record->stageLabel())->color(fn (OperationStage $state): string => match ($state) {
                    OperationStage::Draft => 'gray', OperationStage::Waiting => 'warning', OperationStage::Ready => 'info', OperationStage::InTransit, OperationStage::PartiallyReceived => 'primary', OperationStage::Done => 'success', OperationStage::Canceled => 'danger',
                }),
            ])
            ->groups(self::groups($operationType))
            ->filters([
                TableQueryBuilder::make(self::constraints($operationType)),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (InventoryOperation $record): bool => $record->isDraft()),
                DeleteAction::make()->visible(fn (InventoryOperation $record): bool => $record->isDraft()),
            ]);
    }

    /** @return list<Group> */
    private static function groups(?OperationType $operationType): array
    {
        $groups = [
            Group::make('stage')
                ->label(__('Stage'))
                ->getTitleFromRecordUsing(static fn (InventoryOperation $record): string => $record->stageLabel()),
        ];

        if (! $operationType instanceof OperationType) {
            $groups[] = Group::make('operation_type')
                ->label(__('admin.inventory.operation.fields.operation_type'))
                ->getTitleFromRecordUsing(static fn (InventoryOperation $record): string => $record->operation_type->label());
        }

        if (in_array($operationType, [null, OperationType::Delivery, OperationType::InternalTransfer], true)) {
            $groups[] = Group::make('sourceWarehouse.name')
                ->label(__('admin.inventory.operation.fields.source_warehouse'));
        }

        if (in_array($operationType, [null, OperationType::Receipt, OperationType::InternalTransfer], true)) {
            $groups[] = Group::make('destinationWarehouse.name')
                ->label(__('admin.inventory.operation.fields.destination_warehouse'));
        }

        $groups[] = Group::make('scheduled_at')
            ->label(__('admin.inventory.operation.fields.scheduled_at'))
            ->date();

        return $groups;
    }

    /** @return list<Constraint> */
    private static function constraints(?OperationType $operationType): array
    {
        $constraints = [
            SelectConstraint::make('stage')
                ->label(__('Stage'))
                ->options(collect(OperationStage::cases())->mapWithKeys(static fn (OperationStage $stage): array => [$stage->value => $stage === OperationStage::Done && $operationType === OperationType::Delivery
                    ? __('admin.inventory.operation.stages.delivered')
                    : $stage->label()])->all())
                ->multiple(),
            TextConstraint::make('operation_number')->label(__('admin.inventory.operation.fields.operation_number')),
        ];

        if (! $operationType instanceof OperationType) {
            $constraints[] = SelectConstraint::make('operation_type')
                ->label(__('admin.inventory.operation.fields.operation_type'))
                ->options(collect(OperationType::cases())->mapWithKeys(static fn (OperationType $type): array => [$type->value => $type->label()])->all())
                ->multiple();
        }

        if (! $operationType instanceof OperationType || $operationType === OperationType::Receipt) {
            $constraints[] = RelationshipConstraint::make('supplier')
                ->label(__('admin.inventory.operation.fields.supplier'))
                ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple());
        }

        if (! $operationType instanceof OperationType || $operationType === OperationType::Delivery) {
            $constraints[] = RelationshipConstraint::make('customer')
                ->label(__('admin.inventory.operation.fields.customer'))
                ->selectable(IsRelatedToOperator::make()->titleAttribute('company_name')->searchable()->multiple());
            $constraints[] = SelectConstraint::make('delivery_type')
                ->label(__('admin.inventory.operation.fields.delivery_type'))
                ->options(collect(DeliveryType::cases())->mapWithKeys(static fn (DeliveryType $type): array => [$type->value => $type->label()])->all())
                ->multiple();
        }

        if (in_array($operationType, [null, OperationType::Delivery, OperationType::InternalTransfer], true)) {
            $constraints[] = RelationshipConstraint::make('sourceWarehouse')
                ->label(__('admin.inventory.operation.fields.source_warehouse'))
                ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple());
        }

        if (in_array($operationType, [null, OperationType::Receipt, OperationType::InternalTransfer], true)) {
            $constraints[] = RelationshipConstraint::make('destinationWarehouse')
                ->label(__('admin.inventory.operation.fields.destination_warehouse'))
                ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple());
        }

        return [
            ...$constraints,
            RelationshipConstraint::make('responsible')
                ->label(__('admin.inventory.operation.fields.responsible'))
                ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
            DateConstraint::make('scheduled_at')->label(__('admin.inventory.operation.fields.scheduled_at')),
            DateConstraint::make('created_at')->label(__('Created at')),
        ];
    }
}
