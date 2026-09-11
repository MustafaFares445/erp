<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels\Tables;

use App\Enums\InventoryPermission;
use App\Enums\ProductType;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Filament\Resources\StockLevels\Actions\StockDamageActions;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\InventoryStock;
use App\Services\Inventory\StockAvailabilityExplainer;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

final class StockLevelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('productVariant.sku')
                    ->label(__('admin.inventory.stock.variant'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.name')
                    ->label(__('admin.inventory.stock.variant_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('warehouse.code')
                    ->label(__('admin.inventory.stock.warehouse'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('warehouse.name')
                    ->label(__('admin.inventory.stock.warehouse_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('on_hand_quantity')
                    ->label(__('admin.inventory.stock.on_hand_quantity'))
                    ->summarize(Sum::make()->numeric(decimalPlaces: 3))
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('saleable_quantity')
                    ->label(__('admin.inventory.stock.saleable_quantity'))
                    ->state(fn (InventoryStock $record): float => $record->conditionOnHandQuantity(StockCondition::Saleable))
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('quarantine_quantity')
                    ->label(__('admin.inventory.stock.quarantine_quantity'))
                    ->state(fn (InventoryStock $record): float => $record->conditionOnHandQuantity(StockCondition::Quarantine))
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('reserved_quantity')
                    ->label(__('admin.inventory.stock.reserved_quantity'))
                    ->summarize(Sum::make()->numeric(decimalPlaces: 3))
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('damaged_quantity')
                    ->label(__('admin.inventory.stock.damaged_quantity'))
                    ->state(fn (InventoryStock $record): float => $record->conditionOnHandQuantity(StockCondition::Damaged))
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('available_quantity')
                    ->label(__('admin.inventory.stock.available_quantity'))
                    ->state(fn (InventoryStock $record): float => $record->saleableAvailableQuantity())
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('in_transit_quantity')
                    ->label(__('admin.inventory.stock.in_transit_quantity'))
                    ->state(fn (InventoryStock $record): float => $record->inTransitQuantity())
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('product_type')
                    ->label(__('admin.inventory.product_type.label'))
                    ->state(fn (InventoryStock $record): ?ProductType => $record->productVariant?->productType())
                    ->badge()
                    ->formatStateUsing(static fn (ProductType $state): string => $state->label())
                    ->color(static fn (ProductType $state): string => $state->color())
                    ->toggleable(),
                TextColumn::make('total_weight')
                    ->label(__('admin.inventory.product_type.fields.total_weight'))
                    ->state(fn (InventoryStock $record): ?float => $record->productVariant?->weightFor((float) $record->on_hand_quantity))
                    ->suffix(fn (InventoryStock $record): string => $record->productVariant?->weightSuffix() ?? '')
                    ->numeric(decimalPlaces: 3)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label(__('admin.inventory.stock.warehouse'))
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('product_type')
                    ->label(__('admin.inventory.product_type.label'))
                    ->options(ProductType::options())
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        ProductType::fromFilterValues($data['values'] ?? []),
                        fn (Builder $stocks, array $types): Builder => $stocks->whereHas(
                            'productVariant.product',
                            fn (Builder $products): Builder => $products->whereIn('product_type', $types),
                        ),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('availability_breakdown')
                    ->label(__('admin.inventory.stock.availability_breakdown'))
                    ->icon('heroicon-o-question-mark-circle')
                    ->modalHeading(__('admin.inventory.stock.availability_breakdown'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (InventoryStock $record): View => view(
                        'filament.inventory.stock-availability-breakdown',
                        ['explanation' => app(StockAvailabilityExplainer::class)->explainStock($record)],
                    )),
                Action::make('package_movements')
                    ->label(__('admin.resources.packages'))
                    ->url(fn (InventoryStock $record): string => self::packageMovementsUrl($record)),
                Action::make('disposition_quarantine')
                    ->label(__('admin.inventory.condition_change.disposition_quarantine'))
                    ->icon('heroicon-o-arrows-right-left')
                    ->visible(fn (InventoryStock $record): bool => $record->conditionOnHandQuantity(StockCondition::Quarantine) > 0
                        && (auth()->user()?->can(InventoryPermission::ConditionChangeCreate->value) ?? false))
                    ->url(fn (InventoryStock $record): string => InventoryConditionChangeResource::getUrl('create', [
                        'product_variant_id' => $record->product_variant_id,
                        'warehouse_id' => $record->warehouse_id,
                        'base_quantity' => number_format(
                            $record->conditionOnHandQuantity(StockCondition::Quarantine),
                            6,
                            '.',
                            '',
                        ),
                    ])),
                StockDamageActions::damage(),
                StockDamageActions::recover(),
                StockDamageActions::dispose(),
            ]);
    }

    public static function packageMovementsUrl(InventoryStock $stock): string
    {
        return StockMovementResource::getUrl('index', [
            'tableFilters' => [
                'warehouse_id' => ['value' => $stock->warehouse_id],
                'product_variant_id' => ['value' => $stock->product_variant_id],
            ],
        ]);
    }
}
