<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryLots\Tables;

use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\InventorySetting;
use App\Models\Product;
use App\Models\Warehouse;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class InventoryLotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('lot_number')->label('Lot')->searchable()->sortable()->placeholder('—'),
                TextColumn::make('productVariant.sku')->label('SKU')->searchable()->sortable(),
                TextColumn::make('productVariant.product.name')->label('Product')->searchable()->sortable(),
                TextColumn::make('expires_at')->date()->sortable()->placeholder('—'),
                TextColumn::make('days_remaining')
                    ->state(fn (InventoryLot $record): ?int => $record->daysRemaining()),
                TextColumn::make('total_physical')
                    ->label(__('admin.inventory.stock.on_hand_quantity'))
                    ->state(fn (InventoryLot $record): float => $record->totalPhysicalQuantity())
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('saleable_quantity')
                    ->label(__('admin.inventory.stock.saleable_quantity'))
                    ->state(fn (InventoryLot $record): float => $record->totalConditionOnHandQuantity(StockCondition::Saleable))
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('quarantine_quantity')
                    ->label(__('admin.inventory.stock.quarantine_quantity'))
                    ->state(fn (InventoryLot $record): float => $record->totalConditionOnHandQuantity(StockCondition::Quarantine))
                    ->numeric(decimalPlaces: 3)
                    ->toggleable(),
                TextColumn::make('damaged_quantity')
                    ->label(__('admin.inventory.stock.damaged_quantity'))
                    ->state(fn (InventoryLot $record): float => $record->totalConditionOnHandQuantity(StockCondition::Damaged))
                    ->numeric(decimalPlaces: 3)
                    ->toggleable(),
                TextColumn::make('reserved_quantity')
                    ->label(__('admin.inventory.stock.reserved_quantity'))
                    ->state(fn (InventoryLot $record): float => $record->totalConditionReservedQuantity(StockCondition::Saleable))
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('available_quantity')
                    ->state(fn (InventoryLot $record): float => $record->totalAvailableQuantity())
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('warehouse_count')
                    ->label('Warehouses')
                    ->state(fn (InventoryLot $record): int => $record->warehouseCount()),
                TextColumn::make('expiry_state')
                    ->state(fn (InventoryLot $record): string => $record->expiryState())
                    ->badge()
                    ->color(fn (InventoryLot $record): string => match ($record->expiryState()) {
                        'expired' => 'danger',
                        'expiring' => 'warning',
                        'healthy' => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('Warehouse')
                    ->options(fn (): array => Warehouse::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $lotQuery, mixed $warehouseId): Builder => $lotQuery->whereHas(
                            'conditionBalances',
                            fn (Builder $balanceQuery): Builder => $balanceQuery
                                ->where('warehouse_id', $warehouseId)
                                ->where('on_hand_base_quantity', '>', 0),
                        ),
                    )),
                SelectFilter::make('product_id')
                    ->label('Product')
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $lotQuery, mixed $productId): Builder => $lotQuery->whereHas(
                            'productVariant',
                            fn (Builder $variantQuery): Builder => $variantQuery->where('product_id', $productId),
                        ),
                    )),
                Filter::make('expired')
                    ->query(fn (Builder $query): Builder => $query->whereDate('expires_at', '<', today())),
                Filter::make('expiring')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereDate('expires_at', '>=', today())
                        ->whereDate('expires_at', '<=', today()->addDays(InventorySetting::expiryAlertDays()))),
                Filter::make('healthy')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereDate('expires_at', '>', today()->addDays(InventorySetting::expiryAlertDays()))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
