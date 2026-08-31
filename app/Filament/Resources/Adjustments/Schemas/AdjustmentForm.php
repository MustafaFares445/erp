<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\Schemas;

use App\Data\Inventory\AdjustmentData;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryAdjustment;
use App\Models\InventoryLot;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
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
                                $oldQuantity = self::currentOnHand($get, $state);

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

        return InventoryLot::query()
            ->where('product_variant_id', (int) $variantId)
            ->where('warehouse_id', (int) $warehouseId)
            ->where('on_hand_quantity', '>', 0)
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (InventoryLot $lot): array => [
                (int) $lot->getKey() => $lot->lot_number ?? '#'.$lot->getKey(),
            ])
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

        return SerializedInventoryUnit::query()
            ->where('product_variant_id', (int) $variantId)
            ->where(function (Builder $query) use ($warehouseId): void {
                $query->where(function (Builder $query) use ($warehouseId): void {
                    $query->where('warehouse_id', (int) $warehouseId)
                        ->where('status', SerializedInventoryUnitStatus::Available->value);
                })->orWhere('status', SerializedInventoryUnitStatus::AdjustedOut->value);
            })
            ->orderBy('serial_number')
            ->pluck('serial_number', 'id')
            ->all();
    }

    private static function currentOnHand(Get $get, mixed $productVariantId): float
    {
        $warehouseId = $get('../../warehouse_id');

        if (! is_numeric($productVariantId) || ! is_numeric($warehouseId)) {
            return 0.0;
        }

        return Warehouse::query()->find((int) $warehouseId)?->currentOnHand((int) $productVariantId) ?? 0.0;
    }

    private static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
