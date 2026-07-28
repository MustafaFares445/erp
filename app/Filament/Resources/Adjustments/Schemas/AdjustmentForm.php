<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\Schemas;

use App\Data\Inventory\AdjustmentData;
use App\Models\InventoryAdjustment;
use App\Models\ProductVariant;
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
                        Select::make('package_id')
                            ->label(__('admin.inventory.operation.fields.package'))
                            ->relationship('package', 'name', fn (Builder $query, Get $get): Builder => $query
                                ->where('warehouse_id', $get('../../warehouse_id'))
                                ->where('is_active', true))
                            ->searchable()
                            ->preload(),
                        Select::make('serialized_inventory_unit_id')
                            ->label('Serialized unit')
                            ->relationship('serializedUnit', 'serial_number')
                            ->searchable()
                            ->preload(),
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
