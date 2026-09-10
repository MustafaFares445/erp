<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarehouseReplenishmentPolicies\Schemas;

use App\Models\ProductVariant;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
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
                ->required(),
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
}
