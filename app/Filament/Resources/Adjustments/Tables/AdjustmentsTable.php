<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\Tables;

use App\Enums\AdjustmentStatus;
use App\Enums\StockCondition;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Models\InventoryAdjustment;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class AdjustmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('adjustment_number')
                    ->label(__('admin.inventory.adjustment.adjustment_number'))
                    ->placeholder(__('admin.inventory.adjustment.number_pending'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('correctsAdjustment.adjustment_number')
                    ->label(__('admin.inventory.adjustment.corrects_adjustment'))
                    ->placeholder('—')
                    ->url(fn (InventoryAdjustment $record): ?string => $record->corrects_adjustment_id === null
                        ? null
                        : AdjustmentResource::getUrl('view', ['record' => $record->corrects_adjustment_id])),
                TextColumn::make('warehouse.code')
                    ->label(__('admin.inventory.stock.warehouse'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('warehouse.name')
                    ->label(__('admin.inventory.stock.warehouse_name'))
                    ->searchable(),
                TextColumn::make('reason_category')
                    ->label(__('admin.inventory.adjustment.reason_category'))
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->label(__('admin.inventory.adjustment.reason'))
                    ->limit(50),
                TextColumn::make('conditions')
                    ->label(__('admin.inventory.adjustment.stock_condition'))
                    ->state(fn (InventoryAdjustment $record): string => $record->items()
                        ->pluck('stock_condition')
                        ->filter()
                        ->unique()
                        ->map(fn (mixed $condition): string => is_string($condition) ? Str::headline($condition) : '')
                        ->filter()
                        ->implode(', '))
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('admin.inventory.adjustment.status'))
                    ->badge()
                    ->formatStateUsing(fn (AdjustmentStatus $state): string => Str::headline($state->value))
                    ->color(fn (AdjustmentStatus $state): string => match ($state) {
                        AdjustmentStatus::Draft => 'warning',
                        AdjustmentStatus::Confirmed => 'success',
                    }),
                TextColumn::make('items_count')
                    ->label(__('admin.inventory.adjustment.items_count'))
                    ->counts('items'),
                TextColumn::make('createdBy.name')
                    ->label(__('admin.inventory.movement.creator'))
                    ->default(__('admin.inventory.movement.system')),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.inventory.adjustment.status'))
                    ->options(collect(AdjustmentStatus::cases())
                        ->mapWithKeys(fn (AdjustmentStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                SelectFilter::make('warehouse_id')
                    ->label(__('admin.inventory.stock.warehouse'))
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('stock_condition')
                    ->label(__('admin.inventory.adjustment.stock_condition'))
                    ->options([
                        StockCondition::Saleable->value => 'Saleable',
                        StockCondition::Quarantine->value => 'Quarantine',
                        StockCondition::Damaged->value => 'Damaged',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $condition = $data['value'] ?? null;

                        return is_string($condition) && $condition !== ''
                            ? $query->whereHas('items', fn (Builder $items): Builder => $items->where('stock_condition', $condition))
                            : $query;
                    }),
                Filter::make('pending_my_confirmation')
                    ->label('Pending my confirmation')
                    ->query(function (Builder $query): Builder {
                        $userId = auth()->id();

                        if (! is_numeric($userId)) {
                            return $query->whereRaw('1 = 0');
                        }

                        return $query
                            ->where('status', AdjustmentStatus::Draft->value)
                            ->where('created_by', '!=', (int) $userId);
                    }),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (is_string($from)) {
                            $query->whereDate('created_at', '>=', $from);
                        }

                        if (is_string($until)) {
                            $query->whereDate('created_at', '<=', $until);
                        }

                        return $query;
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (InventoryAdjustment $record): bool => $record->isDraft()),
                DeleteAction::make()
                    ->visible(fn (InventoryAdjustment $record): bool => $record->isDraft()),
            ]);
    }
}
