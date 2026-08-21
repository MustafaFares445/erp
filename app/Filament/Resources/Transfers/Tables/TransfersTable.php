<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transfers\Tables;

use App\Enums\TransferStatus;
use App\Models\StockTransfer;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class TransfersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('transfer_number')
                    ->label(__('admin.inventory.transfer.transfer_number'))
                    ->placeholder(__('admin.inventory.transfer.number_pending'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('fromWarehouse.code')
                    ->label(__('admin.inventory.transfer.from_warehouse'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('toWarehouse.code')
                    ->label(__('admin.inventory.transfer.to_warehouse'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.inventory.transfer.status'))
                    ->badge()
                    ->formatStateUsing(fn (TransferStatus $state): string => Str::headline($state->value))
                    ->color(fn (TransferStatus $state): string => match ($state) {
                        TransferStatus::Draft => 'warning',
                        TransferStatus::Dispatched => 'info',
                        TransferStatus::Received => 'success',
                    }),
                TextColumn::make('items_count')
                    ->label(__('admin.inventory.transfer.items_count'))
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
                    ->label(__('admin.inventory.transfer.status'))
                    ->options(collect(TransferStatus::cases())
                        ->mapWithKeys(fn (TransferStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                SelectFilter::make('from_warehouse_id')
                    ->label(__('admin.inventory.transfer.from_warehouse'))
                    ->relationship('fromWarehouse', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('to_warehouse_id')
                    ->label(__('admin.inventory.transfer.to_warehouse'))
                    ->relationship('toWarehouse', 'name')
                    ->searchable()
                    ->preload(),
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
                    ->visible(fn (StockTransfer $record): bool => $record->isDraft()),
                DeleteAction::make()
                    ->visible(fn (StockTransfer $record): bool => $record->isDraft()),
                RestoreAction::make()
                    ->visible(fn (StockTransfer $record): bool => $record->trashed()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
