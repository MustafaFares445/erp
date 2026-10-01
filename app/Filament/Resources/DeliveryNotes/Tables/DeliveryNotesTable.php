<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryNotes\Tables;

use App\Enums\OperationStage;
use App\Models\InventoryOperation;
use App\Models\Warehouse;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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
            ->searchPlaceholder(__('Search by delivery note number or customer name'))
            ->columns([
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
            ->filters([
                SelectFilter::make('stage')->options(collect(OperationStage::cases())
                    ->mapWithKeys(fn (OperationStage $stage): array => [$stage->value => $stage->label()])
                    ->all()),
                SelectFilter::make('source_warehouse_id')
                    ->label(__('admin.inventory.operation.fields.source_warehouse'))
                    ->searchable()
                    ->options(fn (): array => Warehouse::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('uninvoiced')
                    ->label(__('Uninvoiced'))
                    ->query(fn (Builder $query): Builder => $query
                        ->where('stage', OperationStage::Done->value)
                        ->whereDoesntHave('invoiceDeliveryLink')),
                Filter::make('scheduled_between')
                    ->schema([
                        DatePicker::make('from')->label(__('Scheduled from')),
                        DatePicker::make('until')->label(__('Scheduled until')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when(self::dateFrom($data['from'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('scheduled_at', '>=', $date))
                        ->when(self::dateFrom($data['until'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('scheduled_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    private static function dateFrom(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
