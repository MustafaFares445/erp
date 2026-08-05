<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReports\Tables;

use App\Enums\InventoryImportItemStatus;
use App\Enums\InventoryImportRunStatus;
use App\Enums\InventoryReportType;
use App\Enums\MovementType;
use App\Enums\PricingTierType;
use App\Enums\PricingTierVisibility;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\Brand;
use App\Models\InventoryImportRun;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class InventoryReportFilters
{
    /** @return array<int, BaseFilter> */
    public static function for(InventoryReportType $type): array
    {
        return match ($type) {
            InventoryReportType::Catalog => self::catalog(),
            InventoryReportType::StockLevels => self::stock(),
            InventoryReportType::Movements => self::movements(),
            InventoryReportType::Devices => self::devices(),
            InventoryReportType::ExpiryLots => self::expiryLots(),
            InventoryReportType::SupplierComparison => self::suppliers(),
            InventoryReportType::PriceHistory => self::priceHistory(),
            InventoryReportType::PricingTiers => self::tiers(),
            InventoryReportType::CustomerAssignments => self::assignments(),
            InventoryReportType::FloorOverrides => self::floorOverrides(),
            InventoryReportType::ImportRuns => self::importRuns(),
            InventoryReportType::ImportResults => self::importResults(),
        };
    }

    /** @return array<int, BaseFilter> */
    private static function catalog(): array
    {
        return [
            self::select('product_id', self::options(Product::class)),
            self::select('category_id', self::options(ProductCategory::class)),
            self::select('brand_id', self::options(Brand::class)),
            self::select('status', self::enumOptions(ProductStatus::cases())),
            // Translated labels rather than enumOptions(), so a product type reads the same here
            // as everywhere else it is shown.
            self::select('product_type', ProductType::options()),
            self::active(),
        ];
    }

    /** @return array<int, BaseFilter> */
    private static function stock(): array
    {
        return [
            self::warehouse(),
            self::variant(),
            self::select('availability_state', ['available' => 'Available', 'low_stock' => 'Low stock', 'out_of_stock' => 'Out of stock']),
            self::select('product_type', ProductType::options()),
        ];
    }

    /** @return array<int, BaseFilter> */
    private static function movements(): array
    {
        return [self::warehouse(), self::variant(), self::select('movement_type', self::enumOptions(MovementType::cases())), self::dateRange()];
    }

    /** @return array<int, BaseFilter> */
    private static function devices(): array
    {
        return [
            self::warehouse(),
            self::variant(),
            self::select('status', self::enumOptions(SerializedInventoryUnitStatus::cases())),
            Filter::make('identity')->schema([TextInput::make('identity')])->query(self::noOp(...)),
        ];
    }

    /** @return array<int, BaseFilter> */
    private static function expiryLots(): array
    {
        return [
            self::warehouse(),
            self::variant(),
            self::select('expiry_state', ['expired' => 'Expired', 'expiring' => 'Expiring', 'healthy' => 'Healthy', 'no_expiry' => 'No expiry']),
            self::dateRange(),
        ];
    }

    /** @return array<int, BaseFilter> */
    private static function suppliers(): array
    {
        return [
            self::select('supplier_id', self::options(Supplier::class)),
            self::variant(),
            self::select('country_code', self::distinctOptions('country_code')),
            self::select('currency_code', self::distinctOptions('currency_code')),
            self::active(),
        ];
    }

    /** @return array<int, BaseFilter> */
    private static function priceHistory(): array
    {
        return [self::variant(), self::select('changed_by', self::options(User::class)), self::dateRange()];
    }

    /** @return array<int, BaseFilter> */
    private static function tiers(): array
    {
        return [
            self::select('customer_user_id', self::options(User::class)),
            self::select('product_id', self::options(Product::class)),
            self::select('tier_type', self::enumOptions(PricingTierType::cases())),
            self::select('visibility', self::enumOptions(PricingTierVisibility::cases())),
            self::select('eligibility_state', ['current' => 'Current', 'scheduled' => 'Scheduled', 'expired' => 'Expired']),
            self::active(),
            self::dateRange(),
        ];
    }

    /** @return array<int, BaseFilter> */
    private static function assignments(): array
    {
        return [
            self::select('customer_user_id', self::options(User::class)),
            self::select('product_id', self::options(Product::class)),
            self::select('tier_type', self::enumOptions(PricingTierType::cases())),
            self::active(),
        ];
    }

    /** @return array<int, BaseFilter> */
    private static function floorOverrides(): array
    {
        return [
            self::variant(),
            self::select('customer_user_id', self::options(User::class)),
            self::select('pricing_tier_id', self::options(PricingTier::class)),
            self::dateRange(),
        ];
    }

    /** @return array<int, BaseFilter> */
    private static function importRuns(): array
    {
        return [
            self::select('status', self::enumOptions(InventoryImportRunStatus::cases())),
            self::select('created_by', self::options(User::class)),
            self::dateRange(),
        ];
    }

    /** @return array<int, BaseFilter> */
    private static function importResults(): array
    {
        return [
            self::select('inventory_import_run_id', self::importRunOptions()),
            self::select('status', self::enumOptions(InventoryImportItemStatus::cases())),
            self::select('created_by', self::options(User::class)),
            self::dateRange(),
        ];
    }

    private static function warehouse(): SelectFilter
    {
        return self::select('warehouse_id', self::options(Warehouse::class));
    }

    private static function variant(): SelectFilter
    {
        return self::select('product_variant_id', self::options(ProductVariant::class));
    }

    /** @param array<array-key, mixed> $options */
    private static function select(string $name, array $options): SelectFilter
    {
        return SelectFilter::make($name)
            ->options(self::stringOptions($options))
            ->searchable()
            ->query(self::noOp(...));
    }

    private static function active(): TernaryFilter
    {
        return TernaryFilter::make('is_active')->query(self::noOp(...));
    }

    private static function dateRange(): Filter
    {
        return Filter::make('date_range')
            ->schema([DatePicker::make('from'), DatePicker::make('until')])
            ->query(self::noOp(...));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function noOp(Builder $query): Builder
    {
        return $query;
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
    private static function distinctOptions(string $column): array
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
