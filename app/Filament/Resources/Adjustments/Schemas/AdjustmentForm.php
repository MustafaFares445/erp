<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\Schemas;

use App\Data\Inventory\AdjustmentData;
use App\Enums\ConditionChangeReason;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryAdjustment;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AdjustmentForm
{
    public static function configure(Schema $schema): Schema
    {
        $rules = AdjustmentData::rules();

        return $schema
            ->components([
                Select::make('warehouse_id')
                    ->relationship('warehouse', 'name', fn (Builder $query): Builder => $query->where('is_active', true))
                    ->rules($rules['warehouse_id'])
                    ->searchable()
                    ->preload()
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Choose the warehouse whose on-hand quantities will be corrected by this adjustment.')
                    ->disabled(fn (?InventoryAdjustment $record): bool => $record?->isConfirmed() ?? false),
                TextInput::make('adjustment_number')
                    ->label(__('admin.inventory.adjustment.adjustment_number'))
                    ->placeholder(__('admin.inventory.adjustment.number_pending'))
                    ->disabled()
                    ->dehydrated(false)
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'The adjustment number is assigned automatically when the record is saved.'),
                Select::make('reason_category')
                    ->label(__('admin.inventory.adjustment.reason_category'))
                    ->options(collect(ConditionChangeReason::cases())
                        ->mapWithKeys(fn (ConditionChangeReason $reason): array => [
                            $reason->value => str($reason->name)->headline()->toString(),
                        ])
                        ->all())
                    ->default(ConditionChangeReason::Other->value)
                    ->rules($rules['reason_category'])
                    ->required()
                    ->disabled(fn (?InventoryAdjustment $record): bool => $record?->isConfirmed() ?? false),
                Textarea::make('reason')
                    ->label(__('admin.inventory.adjustment.reason'))
                    ->rules($rules['reason'])
                    ->maxLength(1000)
                    ->columnSpanFull()
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Record why the correction is required so the change can be audited.')
                    ->disabled(fn (?InventoryAdjustment $record): bool => $record?->isConfirmed() ?? false),
                Repeater::make('items')
                    ->relationship()
                    ->label(__('admin.sections.operations'))
                    ->rules($rules['items'])
                    ->schema([
                        Select::make('product_variant_id')
                            ->label(__('admin.inventory.stock.variant'))
                            ->options(fn (): array => ProductVariant::query()
                                ->where('is_active', true)
                                ->orderBy('sku')
                                ->pluck('sku', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                $set('inventory_lot_id', null);
                                $set('serialized_inventory_unit_id', null);
                                $oldQuantity = self::currentOnHand($get, $state);

                                $set('old_quantity', $oldQuantity);
                                $set('difference', self::toFloat($get('new_quantity')) - $oldQuantity);
                            }),
                        Select::make('stock_condition')
                            ->label(__('admin.inventory.adjustment.stock_condition'))
                            ->options(self::conditionOptions())
                            ->default(StockCondition::Saleable->value)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                $set('inventory_lot_id', null);
                                $set('serialized_inventory_unit_id', null);
                                $oldQuantity = self::currentOnHand($get, $get('product_variant_id'));

                                $set('old_quantity', $oldQuantity);
                                $set('difference', self::toFloat($get('new_quantity')) - $oldQuantity);
                            }),
                        Select::make('inventory_lot_id')
                            ->label(__('admin.inventory.lot.fields.lot'))
                            ->options(fn (Get $get): array => self::lotOptions($get))
                            ->searchable()
                            ->preload()
                            ->visible(fn (Get $get): bool => self::tracksBatches($get('product_variant_id')))
                            ->required(fn (Get $get): bool => self::tracksBatches($get('product_variant_id'))),
                        Select::make('package_id')
                            ->label(__('admin.inventory.operation.fields.package'))
                            ->relationship('package', 'name', fn (Builder $query, Get $get): Builder => $query
                                ->where('warehouse_id', $get('../../warehouse_id'))
                                ->where('is_active', true))
                            ->searchable()
                            ->preload(),
                        Select::make('serialized_inventory_unit_id')
                            ->label('Serialized unit')
                            ->options(fn (Get $get): array => self::serializedUnitOptions($get))
                            ->searchable()
                            ->preload()
                            ->visible(fn (Get $get): bool => self::tracksSerials($get('product_variant_id')))
                            ->required(fn (Get $get): bool => self::tracksSerials($get('product_variant_id'))),
                        TextInput::make('old_quantity')
                            ->label(__('admin.inventory.adjustment.old_quantity'))
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn (Get $get): float => self::currentOnHand($get, $get('product_variant_id'))),
                        TextInput::make('new_quantity')
                            ->label(__('admin.inventory.adjustment.new_quantity'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                $set('difference', self::toFloat($state) - self::currentOnHand($get, $get('product_variant_id')));
                            }),
                        TextInput::make('difference')
                            ->label(__('admin.inventory.adjustment.difference'))
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn (Get $get): float => self::toFloat($get('new_quantity')) - self::currentOnHand($get, $get('product_variant_id'))),
                    ])
                    ->table([
                        TableColumn::make(__('admin.inventory.stock.variant')),
                        TableColumn::make(__('admin.inventory.adjustment.stock_condition')),
                        TableColumn::make('Serialized unit'),
                        TableColumn::make(__('admin.inventory.lot.fields.lot')),
                        TableColumn::make(__('admin.inventory.operation.fields.package')),
                        TableColumn::make(__('admin.inventory.adjustment.old_quantity')),
                        TableColumn::make(__('admin.inventory.adjustment.new_quantity')),
                        TableColumn::make(__('admin.inventory.adjustment.difference')),
                    ])
                    ->addActionLabel('New inventory adjustment item')
                    ->defaultItems(0)
                    ->columnSpanFull()
                    ->saveRelationshipsWhenHidden()
                    ->visible(fn (?InventoryAdjustment $record): bool => ! $record?->exists),
            ])
            ->disabled(fn (?InventoryAdjustment $record): bool => $record?->isConfirmed() ?? false);
    }

    private static function tracksBatches(mixed $productVariantId): bool
    {
        if (! is_numeric($productVariantId)) {
            return false;
        }

        return ProductVariant::query()->with('product')->find((int) $productVariantId)?->productType()?->tracksBatches() === true;
    }

    private static function tracksSerials(mixed $productVariantId): bool
    {
        if (! is_numeric($productVariantId)) {
            return false;
        }

        return ProductVariant::query()->with('product')->find((int) $productVariantId)?->productType()?->tracksSerials() === true;
    }

    /** @return array<int, string> */
    private static function lotOptions(Get $get): array
    {
        $variantId = $get('product_variant_id');
        $warehouseId = $get('../../warehouse_id');

        if (! is_numeric($variantId) || ! is_numeric($warehouseId)) {
            return [];
        }

        $condition = self::selectedCondition($get);

        return InventoryLot::query()
            ->canonical()
            ->where('product_variant_id', (int) $variantId)
            ->whereHas('conditionBalances', fn (Builder $query): Builder => $query
                ->where('warehouse_id', (int) $warehouseId))
            ->orderBy('lot_number')
            ->get()
            ->mapWithKeys(function (InventoryLot $lot) use ($warehouseId, $condition): array {
                $lotId = self::integerKey($lot);

                return [$lotId => sprintf(
                    '%s — %.3f %s',
                    $lot->lot_number ?? '#'.$lotId,
                    $lot->conditionOnHandQuantity($condition, (int) $warehouseId),
                    str($condition->value)->headline()->lower()->toString(),
                )];
            })
            ->all();
    }

    /** @return array<int, string> */
    private static function serializedUnitOptions(Get $get): array
    {
        $variantId = $get('product_variant_id');
        $warehouseId = $get('../../warehouse_id');

        if (! is_numeric($variantId) || ! is_numeric($warehouseId)) {
            return [];
        }

        $lotId = self::nullableInteger($get('inventory_lot_id'));
        $condition = self::selectedCondition($get);
        $presentStatus = self::presentSerialStatus($condition);

        return SerializedInventoryUnit::query()
            ->where('product_variant_id', (int) $variantId)
            ->where('stock_condition', $condition->value)
            ->where(function (Builder $query) use ($warehouseId, $presentStatus): void {
                $query->where(function (Builder $query) use ($warehouseId, $presentStatus): void {
                    $query->where('warehouse_id', (int) $warehouseId)
                        ->where('status', $presentStatus->value);
                })->orWhere(function (Builder $query): void {
                    $query->whereNull('warehouse_id')
                        ->where('status', SerializedInventoryUnitStatus::AdjustedOut->value);
                });
            })
            ->when(
                $lotId !== null,
                fn (Builder $query): Builder => $query->where('inventory_lot_id', $lotId),
            )
            ->orderBy('serial_number')
            ->get()
            ->mapWithKeys(fn (SerializedInventoryUnit $unit): array => [
                self::integerKey($unit) => (string) $unit->serial_number,
            ])
            ->all();
    }

    private static function currentOnHand(Get $get, mixed $productVariantId): float
    {
        $warehouseId = $get('../../warehouse_id');

        if (! is_numeric($productVariantId) || ! is_numeric($warehouseId)) {
            return 0.0;
        }

        $condition = self::selectedCondition($get);
        $serializedUnitId = self::nullableInteger($get('serialized_inventory_unit_id'));

        if ($serializedUnitId !== null) {
            return SerializedInventoryUnit::query()
                ->whereKey($serializedUnitId)
                ->where('warehouse_id', (int) $warehouseId)
                ->where('status', self::presentSerialStatus($condition)->value)
                ->where('stock_condition', $condition->value)
                ->exists() ? 1.0 : 0.0;
        }

        $lotId = self::nullableInteger($get('inventory_lot_id'));

        if ($lotId !== null) {
            $lot = InventoryLot::query()->canonical()->find($lotId);

            return $lot instanceof InventoryLot
                ? $lot->conditionOnHandQuantity($condition, (int) $warehouseId)
                : 0.0;
        }

        $stock = InventoryStock::query()
            ->where('product_variant_id', (int) $productVariantId)
            ->where('warehouse_id', (int) $warehouseId)
            ->first();

        return $stock instanceof InventoryStock
            ? $stock->conditionOnHandQuantity($condition)
            : 0.0;
    }

    /** @return array<string, string> */
    private static function conditionOptions(): array
    {
        return [
            StockCondition::Saleable->value => 'Saleable',
            StockCondition::Quarantine->value => 'Quarantine',
            StockCondition::Damaged->value => 'Damaged',
        ];
    }

    private static function selectedCondition(Get $get): StockCondition
    {
        $value = $get('stock_condition');

        if ($value instanceof StockCondition && $value->isMaterialized()) {
            return $value;
        }

        if (is_string($value)) {
            $condition = StockCondition::tryFrom($value);

            if ($condition?->isMaterialized() === true) {
                return $condition;
            }
        }

        return StockCondition::Saleable;
    }

    private static function presentSerialStatus(StockCondition $condition): SerializedInventoryUnitStatus
    {
        return $condition === StockCondition::Damaged
            ? SerializedInventoryUnitStatus::Damaged
            : SerializedInventoryUnitStatus::Available;
    }

    private static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function integerKey(Model $model): int
    {
        $key = $model->getKey();

        if (! is_int($key)) {
            throw new LogicException('Inventory records must use integer identifiers.');
        }

        return $key;
    }

    private static function nullableInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
