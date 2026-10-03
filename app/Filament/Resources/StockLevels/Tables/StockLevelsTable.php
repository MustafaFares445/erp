<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels\Tables;

use App\Enums\InventoryPermission;
use App\Enums\ProductType;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Filament\Resources\StockLevels\Actions\StockDamageActions;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\InventoryStock;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\StockAvailabilityExplainer;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\NumberConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
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
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    ->numeric(decimalPlaces: 3)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('quarantine_quantity')
                    ->label(__('admin.inventory.stock.quarantine_quantity'))
                    ->state(fn (InventoryStock $record): float => $record->conditionOnHandQuantity(StockCondition::Quarantine))
                    ->numeric(decimalPlaces: 3)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reserved_quantity')
                    ->label(__('admin.inventory.stock.reserved_quantity'))
                    ->summarize(Sum::make()->numeric(decimalPlaces: 3))
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('damaged_quantity')
                    ->label(__('admin.inventory.stock.damaged_quantity'))
                    ->state(fn (InventoryStock $record): float => $record->conditionOnHandQuantity(StockCondition::Damaged))
                    ->numeric(decimalPlaces: 3)
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    ->toggleable(isToggledHiddenByDefault: true),
                // Grains are bought and sold by weight, so the balance a grain operator cares
                // about is the derived total weight, not the count of stock units.
                TextColumn::make('total_weight')
                    ->label(__('admin.inventory.product_type.fields.total_weight'))
                    ->state(fn (InventoryStock $record): ?float => $record->productVariant?->weightFor((float) $record->on_hand_quantity))
                    ->suffix(fn (InventoryStock $record): string => $record->productVariant?->weightSuffix() ?? '')
                    ->numeric(decimalPlaces: 3)
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reorder_level')
                    ->label(__('admin.inventory.stock.reorder_level'))
                    ->state(fn (InventoryStock $record): ?string => $record->replenishmentPolicy()?->min_quantity)
                    ->numeric(decimalPlaces: 3)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('low_stock')
                    ->label(__('admin.inventory.stock.low_stock'))
                    ->state(fn (InventoryStock $record): string => self::isLowStock($record)
                        ? __('admin.inventory.stock.low_stock')
                        : __('admin.inventory.stock.healthy'))
                    ->badge()
                    ->color(fn (InventoryStock $record): string => self::isLowStock($record) ? 'danger' : 'success'),
            ])
            ->groups([
                Group::make('warehouse.name')->label(__('admin.inventory.stock.warehouse')),
                Group::make('productVariant.name')->label(__('admin.inventory.stock.variant_name')),
                Group::make('updated_at')->label(__('Updated at'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    TextConstraint::make('variant_sku')
                        ->label(__('admin.inventory.stock.variant'))
                        ->attribute('productVariant.sku'),
                    TextConstraint::make('variant_name')
                        ->label(__('admin.inventory.stock.variant_name'))
                        ->attribute('productVariant.name'),
                    RelationshipConstraint::make('warehouse')
                        ->label(__('admin.inventory.stock.warehouse'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    // Balances key on the variant, so the type is reached through the product.
                    SelectConstraint::make('product_type')
                        ->label(__('admin.inventory.product_type.label'))
                        ->attribute('productVariant.product.product_type')
                        ->options(ProductType::options())
                        ->multiple(),
                    NumberConstraint::make('on_hand_quantity')->label(__('admin.inventory.stock.on_hand_quantity')),
                    NumberConstraint::make('reserved_quantity')->label(__('admin.inventory.stock.reserved_quantity')),
                    NumberConstraint::make('damaged_quantity')->label(__('admin.inventory.stock.damaged_quantity')),
                ]),
                Filter::make('low_stock')
                    ->label(__('admin.inventory.stock.low_stock'))
                    ->query(fn (Builder $query): Builder => $query->whereExists(WarehouseReplenishmentPolicy::breachedSubquery())),
                Filter::make('reserved')
                    ->label(__('admin.resources.reservations'))
                    ->query(fn (Builder $query): Builder => $query->where('reserved_quantity', '>', 0)),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('availability_breakdown')
                    ->label(__('admin.inventory.stock.availability_breakdown'))
                    ->icon('heroicon-o-question-mark-circle')
                    ->modalHeading(__('admin.inventory.stock.availability_breakdown'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('admin.inventory.stock.actions.close'))
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

    private static function isLowStock(InventoryStock $stock): bool
    {
        return $stock->replenishmentPolicy()?->isBreachedBy($stock) ?? false;
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
