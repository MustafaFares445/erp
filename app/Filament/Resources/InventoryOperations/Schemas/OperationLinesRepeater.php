<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Schemas;

use App\Enums\OperationType;
use App\Models\Product;
use App\Models\ProductVariant;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

final class OperationLinesRepeater
{
    public static function make(): Repeater
    {
        return Repeater::make('lines')
            ->relationship()
            ->columns(3)
            ->schema([
                Select::make('product_id')
                    ->label(__('admin.inventory.operation.fields.product'))
                    ->options(fn (): array => Product::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->dehydrated(false)
                    ->required()
                    ->afterStateHydrated(function (Select $component, mixed $state, Get $get): void {
                        if ($state !== null || ! is_numeric($get('product_variant_id'))) {
                            return;
                        }

                        $component->state(ProductVariant::query()
                            ->whereKey((int) $get('product_variant_id'))
                            ->value('product_id'));
                    })
                    ->afterStateUpdated(function (Set $set): void {
                        $set('product_variant_id', null);
                    }),
                Select::make('product_variant_id')
                    ->label(__('admin.inventory.operation.fields.variant'))
                    ->options(fn (Get $get): array => self::variantOptions($get('product_id')))
                    ->searchable()
                    ->preload()
                    ->disabled(fn (Get $get): bool => ! is_numeric($get('product_id')))
                    ->required(),
                TextInput::make('quantity')
                    ->label(__('admin.inventory.operation.fields.demand'))
                    ->numeric()
                    ->minValue(0.001)
                    ->required(),
                Select::make('unit_id')
                    ->label(__('admin.inventory.operation.fields.unit'))
                    ->relationship('unit', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('package_id')
                    ->label(__('admin.inventory.operation.fields.package'))
                    ->relationship('package', 'name', function (Builder $query, Get $get): Builder {
                        $warehouseField = $get('../../operation_type') === OperationType::Receipt->value
                            ? '../../destination_warehouse_id'
                            : '../../source_warehouse_id';
                        $warehouseId = self::toInteger($get($warehouseField));

                        return $query
                            ->where('is_active', true)
                            ->when($warehouseId !== null, fn (Builder $query): Builder => $query->where('warehouse_id', $warehouseId));
                    })
                    ->searchable()
                    ->preload(),
                Checkbox::make('is_picked')
                    ->label(__('admin.inventory.operation.fields.picked')),
            ])
            ->defaultItems(1)
            ->columnSpanFull();
    }

    /** @return array<int|string, string> */
    private static function variantOptions(mixed $productId): array
    {
        if (! is_numeric($productId)) {
            return [];
        }

        return ProductVariant::query()
            ->where('product_id', (int) $productId)
            ->where('is_active', true)
            ->orderBy('sku')
            ->get(['id', 'sku'])
            ->mapWithKeys(static function (ProductVariant $variant): array {
                $variantId = $variant->getKey();

                if (is_int($variantId)) {
                    return [$variantId => $variant->sku];
                }

                if (! is_string($variantId) || ! ctype_digit($variantId)) {
                    throw new \LogicException('An inventory operation variant must have a numeric ID.');
                }

                return [(int) $variantId => $variant->sku];
            })
            ->all();
    }

    private static function toInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (! is_string($value) || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
