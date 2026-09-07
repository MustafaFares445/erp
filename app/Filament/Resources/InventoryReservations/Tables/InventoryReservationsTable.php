<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReservations\Tables;

use App\Enums\ReservationStatus;
use App\Filament\Resources\InventoryReservations\Actions\InventoryReservationActions;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Models\InventoryReservation;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class InventoryReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('productVariant.sku')->label('SKU')->searchable()->sortable(),
                TextColumn::make('productVariant.name')->label('Variant')->searchable(),
                TextColumn::make('warehouse.name')->label('Warehouse')->searchable()->sortable(),
                TextColumn::make('base_quantity')->label('Base Qty')->numeric(decimalPlaces: 6)->sortable(),
                TextColumn::make('allocations_count')->counts('allocations')->label('Allocations'),
                TextColumn::make('source_document')
                    ->label('Source document')
                    ->state(fn (InventoryReservation $record): string => InventoryReservationResource::sourceDocumentLabel($record))
                    ->url(fn (InventoryReservation $record): ?string => InventoryReservationResource::sourceDocumentUrl($record))
                    ->openUrlInNewTab(false),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('expires_at')->dateTime()->placeholder('No expiry')->sortable(),
                TextColumn::make('releasedBy.name')->label('Released by')->placeholder('—'),
                TextColumn::make('release_reason')->label('Release reason')->limit(50)->placeholder('—'),
                TextColumn::make('released_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ReservationStatus::cases())
                        ->mapWithKeys(fn (ReservationStatus $status): array => [
                            $status->value => str($status->name)->headline()->toString(),
                        ])
                        ->all()),
                SelectFilter::make('warehouse_id')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('product_variant_id')
                    ->relationship('productVariant', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('expiring_within_7_days')
                    ->label(__('admin.inventory.reservation.filters.expiring_within_7_days'))
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', ReservationStatus::Active->value)
                        ->whereNotNull('expires_at')
                        ->whereBetween('expires_at', [now(), now()->addDays(7)])),
            ])
            ->recordActions([
                ViewAction::make(),
                InventoryReservationActions::release(),
            ])
            ->toolbarActions([
                InventoryReservationActions::releaseSelected(),
            ])
            ->recordUrl(fn (InventoryReservation $record): string => InventoryReservationResource::getUrl(
                'view',
                ['record' => $record],
            ));
    }
}
