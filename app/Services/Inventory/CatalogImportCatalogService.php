<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryImportRowResult;
use App\Data\Inventory\VariantPricingData;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final readonly class CatalogImportCatalogService
{
    public function __construct(
        private CatalogImportValidator $validator,
        private ProductPricingService $productPricingService,
    ) {}

    /**
     * @param  array<string, string>  $payload
     * @return array{ProductVariant, InventoryImportRowResult}
     */
    public function apply(array $payload, User $actor): array
    {
        $unit = $this->resolveUnit($payload);
        $product = $this->saveProduct($payload, $actor);
        [$variant, $operation] = $this->saveVariant($payload, $product, $unit, $actor);
        $this->savePricing($payload, $variant, $actor);
        $this->saveSupplierReference($payload, $variant);
        $this->saveAttributes($payload, $variant);

        return [$variant, InventoryImportRowResult::forVariant($variant, $operation)];
    }

    /** @param array<string, string> $payload */
    public function resolveSupplier(array $payload): ?Supplier
    {
        if (! isset($payload['supplier_code']) && ! isset($payload['supplier_name'])) {
            return null;
        }

        return Supplier::query()->firstOrCreate(
            ['code' => $payload['supplier_code'] ?? Str::upper(Str::slug($payload['supplier_name']))],
            ['name' => $payload['supplier_name'] ?? $payload['supplier_code']],
        );
    }

    /** @param array<string, string> $payload */
    private function saveProduct(array $payload, User $actor): Product
    {
        $brand = $this->resolveBrand($payload);
        $category = $this->resolveCategory($payload);
        $product = Product::query()->firstOrNew(['name' => $payload['product_name']]);
        $values = [
            'name_ar' => $payload['product_name_ar'] ?? $product->name_ar,
            'brand_id' => $brand?->getKey() ?? $product->brand_id,
            'category_id' => $category?->getKey() ?? $product->category_id,
            'created_by' => $product->exists ? $product->created_by : $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ];

        if (isset($payload['product_status'])) {
            $values['status'] = $payload['product_status'];
        }

        $product->forceFill($values)->save();

        return $product;
    }

    /**
     * @param  array<string, string>  $payload
     * @return array{ProductVariant, string}
     */
    private function saveVariant(array $payload, Product $product, ?Unit $unit, User $actor): array
    {
        $variant = ProductVariant::query()->firstOrNew(['sku' => $payload['sku']]);
        $operation = $variant->exists ? 'catalog_updated' : 'catalog_created';
        $values = [
            'product_id' => $product->getKey(),
            'name' => $payload['variant_name'],
            'name_ar' => $payload['variant_name_ar'] ?? $variant->name_ar,
            'barcode' => $payload['barcode'] ?? $variant->barcode,
            'unit_id' => $unit?->getKey() ?? $variant->unit_id,
            'track_serials' => $this->validator->tracksSerials($payload),
            'track_expiry' => $this->validator->tracksExpiry($payload),
            'updated_by' => $actor->getKey(),
        ];

        if (isset($payload['product_status'])) {
            $values['status'] = $payload['product_status'];
        }

        if (! $variant->exists) {
            $values['created_by'] = $actor->getKey();
        }

        $variant->forceFill($values)->save();

        return [$variant, $operation];
    }

    /** @param array<string, string> $payload */
    private function savePricing(array $payload, ProductVariant $variant, User $actor): void
    {
        if (! collect(['cost_price', 'markup_percent', 'min_price'])->contains(fn (string $key): bool => isset($payload[$key]))) {
            return;
        }

        $this->productPricingService->updateFromInventoryImport(
            $variant,
            new VariantPricingData(
                costPrice: isset($payload['cost_price']) ? (float) $payload['cost_price'] : $this->floatOrNull($variant->cost_price),
                markupPercent: isset($payload['markup_percent']) ? (float) $payload['markup_percent'] : $this->floatOrNull($variant->markup_percent),
                minimumPrice: isset($payload['min_price']) ? (float) $payload['min_price'] : $this->floatOrNull($variant->min_price),
            ),
            $actor,
        );
    }

    /** @param array<string, string> $payload */
    private function saveSupplierReference(array $payload, ProductVariant $variant): void
    {
        $supplier = $this->resolveSupplier($payload);

        if (! $supplier instanceof Supplier) {
            return;
        }

        SupplierProductReference::query()->updateOrCreate(
            [
                'supplier_id' => $supplier->getKey(),
                'supplier_item_number' => $payload['supplier_item_number'] ?? $variant->sku,
            ],
            [
                'product_variant_id' => $variant->getKey(),
                'supplier_name' => $supplier->name,
                'country_code' => $payload['country_code'] ?? null,
                'manufacturer' => $payload['manufacturer'] ?? null,
                'purchase_cost' => $payload['cost_price'] ?? null,
                'currency_code' => $payload['currency_code'] ?? 'USD',
            ],
        );
    }

    /** @param array<string, string> $payload */
    private function saveAttributes(array $payload, ProductVariant $variant): void
    {
        $attributes = $this->validator->activeAttributes();

        foreach ($this->validator->attributePayload($payload) as $code => $value) {
            $attribute = $attributes->get($code);

            if (! $attribute instanceof ProductAttribute) {
                continue;
            }

            $attributeValue = $this->resolveAttributeValue($attribute, $value);
            $this->replaceAttributeAssignment($variant, $attribute, $attributeValue);
        }
    }

    private function resolveAttributeValue(ProductAttribute $attribute, string $value): ProductAttributeValue
    {
        $attributeValue = ProductAttributeValue::query()
            ->where('product_attribute_id', $attribute->getKey())
            ->where('is_active', true)
            ->whereRaw('LOWER(value) = ?', [mb_strtolower($value)])
            ->first();

        if ($attributeValue instanceof ProductAttributeValue) {
            return $attributeValue;
        }

        if ($attribute->data_type === 'select') {
            throw new DomainException(sprintf('Attribute %s no longer has the selected active value.', $attribute->code));
        }

        return ProductAttributeValue::query()->create([
            'product_attribute_id' => $attribute->getKey(),
            'value' => $value,
            'is_active' => true,
        ]);
    }

    private function replaceAttributeAssignment(
        ProductVariant $variant,
        ProductAttribute $attribute,
        ProductAttributeValue $value,
    ): void {
        ProductVariantAttributeValue::query()
            ->where('product_variant_id', $variant->getKey())
            ->whereHas(
                'attributeValue',
                fn (Builder $query): Builder => $query->where('product_attribute_id', $attribute->getKey()),
            )
            ->where('product_attribute_value_id', '!=', $value->getKey())
            ->delete();

        ProductVariantAttributeValue::query()->firstOrCreate([
            'product_variant_id' => $variant->getKey(),
            'product_attribute_value_id' => $value->getKey(),
        ]);
    }

    /** @param array<string, string> $payload */
    private function resolveBrand(array $payload): ?Brand
    {
        if (! isset($payload['brand_code']) && ! isset($payload['brand_name'])) {
            return null;
        }

        return Brand::query()->firstOrCreate(
            ['code' => $payload['brand_code'] ?? Str::upper(Str::slug($payload['brand_name']))],
            ['name' => $payload['brand_name'] ?? $payload['brand_code']],
        );
    }

    /** @param array<string, string> $payload */
    private function resolveCategory(array $payload): ?ProductCategory
    {
        if (! isset($payload['category_name'])) {
            return null;
        }

        $parent = isset($payload['parent_category_name'])
            ? ProductCategory::query()->firstOrCreate(['name' => $payload['parent_category_name']])
            : null;

        return ProductCategory::query()->firstOrCreate([
            'name' => $payload['category_name'],
            'parent_id' => $parent?->getKey(),
        ]);
    }

    /** @param array<string, string> $payload */
    private function resolveUnit(array $payload): ?Unit
    {
        if (! isset($payload['unit_symbol'])) {
            return null;
        }

        return Unit::query()->firstOrCreate(
            ['symbol' => $payload['unit_symbol']],
            [
                'name' => $payload['unit_name'] ?? $payload['unit_symbol'],
                'allows_decimal' => filter_var($payload['allows_decimal'] ?? null, FILTER_VALIDATE_BOOL),
            ],
        );
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
