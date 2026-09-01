<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryExports\Schemas;

use App\Enums\InventoryExportType;
use App\Enums\InventoryImportItemStatus;
use App\Enums\InventoryImportRunStatus;
use App\Enums\MovementType;
use App\Enums\ProductStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\Brand;
use App\Models\InventoryImportRun;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;

final class InventoryExportRequestSchema
{
    /** @return array<int, Component> */
    public static function make(InventoryExportType $type): array
    {
        return match ($type) {
            InventoryExportType::Catalog => self::catalog(),
            InventoryExportType::StockLevels => self::stock(),
            InventoryExportType::Movements => self::movements(),
            InventoryExportType::Devices => self::devices(),
            InventoryExportType::ExpiryLots => self::expiryLots(),
            InventoryExportType::SupplierComparison => self::suppliers(),
            InventoryExportType::PriceHistory => self::priceHistory(),
            InventoryExportType::PricingTiers => self::pricingTiers(),
            InventoryExportType::ImportResults => self::importResults(),
        };
    }

    /** @return array<int, Component> */
    private static function catalog(): array
    {
        return [
            self::select('product_id', self::options(Product::class)),
            self::select('category_id', self::options(ProductCategory::class)),
            self::select('brand_id', self::options(Brand::class)),
            self::select('status', self::enumOptions(ProductStatus::cases())),
            self::active(),
        ];
    }

    /** @return array<int, Component> */
    private static function stock(): array
    {
        return [
            self::warehouse(),
            self::variant(),
            self::select('availability_state', ['available' => 'Available', 'low_stock' => 'Low stock', 'out_of_stock' => 'Out of stock']),
        ];
    }

    /** @return array<int, Component> */
    private static function movements(): array
    {
        return [
            self::warehouse(),
            self::variant(),
            self::select('movement_type', self::enumOptions(MovementType::cases())),
            self::select('stock_condition_from', self::enumOptions(StockCondition::cases())),
            self::select('stock_condition_to', self::enumOptions(StockCondition::cases())),
            self::select('source_type', self::movementSourceOptions()),
            ...self::dateRange(),
        ];
    }

    /** @return array<int, Component> */
    private static function devices(): array
    {
        return [
            self::warehouse(),
            self::variant(),
            self::select('status', self::enumOptions(SerializedInventoryUnitStatus::cases())),
            TextInput::make('identity'),
        ];
    }

    /** @return array<int, Component> */
    private static function expiryLots(): array
    {
        return [
            self::warehouse(),
            self::variant(),
            self::select('expiry_state', ['expired' => 'Expired', 'expiring' => 'Expiring', 'healthy' => 'Healthy', 'no_expiry' => 'No expiry']),
            ...self::dateRange(),
        ];
    }

    /** @return array<int, Component> */
    private static function suppliers(): array
    {
        return [
            self::select('supplier_id', self::options(Supplier::class)),
            self::variant(),
            self::select('country_code', self::supplierOptions('country_code')),
            self::select('currency_code', self::supplierOptions('currency_code')),
            self::active(),
        ];
    }

    /** @return array<int, Component> */
    private static function priceHistory(): array
    {
        return [
            self::variant(),
            self::select('changed_by', self::options(User::class)),
            ...self::dateRange(),
        ];
    }

    /** @return array<int, Component> */
    private static function pricingTiers(): array
    {
        return [
            self::select('customer_user_id', self::options(User::class)),
            self::variant(),
            self::active(),
            ...self::dateRange(),
        ];
    }

    /** @return array<int, Component> */
    private static function importResults(): array
    {
        return [
            self::select('inventory_import_run_id', self::importRunOptions()),
            self::select('run_status', self::enumOptions(InventoryImportRunStatus::cases())),
            self::select('item_status', self::enumOptions(InventoryImportItemStatus::cases())),
            self::select('created_by', self::options(User::class)),
            ...self::dateRange(),
        ];
    }

    /** @param array<array-key, mixed> $options */
    private static function select(string $name, array $options): Select
    {
        return Select::make($name)->options(self::stringOptions($options))->searchable();
    }

    private static function warehouse(): Select
    {
        return self::select('warehouse_id', self::options(Warehouse::class));
    }

    private static function variant(): Select
    {
        return self::select('product_variant_id', self::options(ProductVariant::class));
    }

    private static function active(): Select
    {
        return self::select('is_active', [1 => 'Active', 0 => 'Inactive']);
    }

    /** @return array{0: DatePicker, 1: DatePicker} */
    private static function dateRange(): array
    {
        return [DatePicker::make('from'), DatePicker::make('until')];
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<int|string, string>
     */
    private static function options(string $model): array
    {
        return self::stringOptions($model::query()->orderBy('name')->pluck('name', 'id')->all());
    }

    /** @return array<int|string, string> */
    private static function movementSourceOptions(): array
    {
        return self::stringOptions(InventoryMovement::query()
            ->whereNotNull('source_type')
            ->distinct()
            ->orderBy('source_type')
            ->pluck('source_type', 'source_type')
            ->all());
    }

    /** @return array<int|string, string> */
    private static function importRunOptions(): array
    {
        return self::stringOptions(InventoryImportRun::query()->latest()->limit(100)->pluck('id', 'id')->all());
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return array<int|string, string>
     */
    private static function enumOptions(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $label = is_string($case->value) ? $case->value : (string) $case->value;
            $options[$case->value] = str($label)->replace('_', ' ')->title()->toString();
        }

        return $options;
    }

    /** @return array<int|string, string> */
    private static function supplierOptions(string $column): array
    {
        return self::stringOptions(SupplierProductReference::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all());
    }

    /**
     * @param  array<array-key, mixed>  $options
     * @return array<int|string, string>
     */
    private static function stringOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }
}
