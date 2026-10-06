<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarehouseReplenishmentPolicies\Schemas;

use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

final class WarehouseReplenishmentPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('warehouse_id')
                ->label(__('admin.inventory.replenishment.fields.warehouse'))
                ->options(fn (): array => Warehouse::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->preload()
                ->required()
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where(
                        'product_variant_id',
                        is_numeric($get('product_variant_id')) ? (int) $get('product_variant_id') : 0,
                    ),
                ),
            Select::make('product_variant_id')
                ->label(__('admin.inventory.replenishment.fields.product_variant'))
                ->options(fn (): array => ProductVariant::query()->orderBy('sku')->pluck('sku', 'id')->all())
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(static fn (Set $set): mixed => $set('preferred_supplier_id', null))
                ->required(),
            Select::make('preferred_supplier_id')
                ->label(__('Preferred supplier'))
                ->options(fn (Get $get): array => self::preferredSupplierOptions($get('product_variant_id')))
                ->searchable()
                ->preload()
                ->placeholder(__('Best eligible supplier'))
                ->helperText(__('Optional. The supplier must have a currently valid Supplier Product for this variant. If left blank, recommendations choose the best active source.')),
            TextInput::make('min_quantity')
                ->label(__('admin.inventory.replenishment.fields.min_quantity'))
                ->numeric()
                ->minValue(0)
                ->step(0.001)
                ->required(),
            TextInput::make('max_quantity')
                ->label(__('admin.inventory.replenishment.fields.max_quantity'))
                ->numeric()
                ->gt('min_quantity')
                ->step(0.001)
                ->required(),
            Toggle::make('is_active')
                ->label(__('admin.inventory.replenishment.fields.is_active'))
                ->default(true),
        ])->columns(2);
    }

    /** @return array<int, string> */
    private static function preferredSupplierOptions(mixed $variantId): array
    {
        if (! is_numeric($variantId)) {
            return [];
        }

        return Supplier::query()
            ->where('is_active', true)
            ->whereHas('productReferences', static fn ($query) => $query
                ->where('product_variant_id', (int) $variantId)
                ->where('availability_status', 'active')
                ->where('is_active', true)
                ->currentlyValid())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
