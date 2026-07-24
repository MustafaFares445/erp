<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type ProductDefinition array{
 *     brand: string,
 *     category: string,
 *     unit: string,
 *     name: string,
 *     name_ar: string,
 *     description: string,
 *     sku: string,
 *     variant_name: string,
 *     variant_name_ar: string,
 *     track_serials: bool,
 *     track_expiry: bool
 * }
 */
final class DentalCatalogSeeder extends Seeder
{
    private const array Units = [
        ['name' => 'Each', 'name_ar' => 'قطعة', 'symbol' => 'EA', 'allows_decimal' => false],
        ['name' => 'Litre', 'name_ar' => 'لتر', 'symbol' => 'L', 'allows_decimal' => true],
    ];

    private const array Categories = [
        'printers' => ['name' => 'Dental 3D Printers', 'name_ar' => 'طابعات أسنان ثلاثية الأبعاد'],
        'materials' => ['name' => 'Dental 3D Printing Materials', 'name_ar' => 'مواد طباعة الأسنان ثلاثية الأبعاد'],
        'post_processing' => ['name' => 'Dental 3D Print Post-Processing', 'name_ar' => 'معالجة ما بعد الطباعة السنية'],
    ];

    private const array Brands = [
        'FORMLABS' => ['name' => 'Formlabs', 'name_ar' => 'فورملابس'],
        'DENTSPLY-SIRONA' => ['name' => 'Dentsply Sirona', 'name_ar' => 'دينتسبلاي سيرونا'],
        'IVOCLAR' => ['name' => 'Ivoclar', 'name_ar' => 'إيفوكلار'],
    ];

    /** @var list<ProductDefinition> */
    private const array Products = [
        ['brand' => 'FORMLABS', 'category' => 'printers', 'unit' => 'EA', 'name' => 'Form 4B', 'name_ar' => 'فورم 4 بي', 'description' => 'Dental MSLA 3D printer for models and biocompatible dental appliances.', 'sku' => 'FORMLABS-FORM-4B', 'variant_name' => 'Form 4B Dental 3D Printer', 'variant_name_ar' => 'طابعة فورم 4 بي للأسنان ثلاثية الأبعاد', 'track_serials' => true, 'track_expiry' => false],
        ['brand' => 'FORMLABS', 'category' => 'materials', 'unit' => 'L', 'name' => 'Precision Model Resin', 'name_ar' => 'راتنج النموذج الدقيق', 'description' => 'High-accuracy beige resin for restorative and diagnostic dental models on the Form 4 series.', 'sku' => 'FORMLABS-PRECISION-MODEL-1L', 'variant_name' => 'Precision Model Resin (Form 4), 1 L', 'variant_name_ar' => 'راتنج النموذج الدقيق (فورم 4)، 1 لتر', 'track_serials' => false, 'track_expiry' => true],
        ['brand' => 'FORMLABS', 'category' => 'materials', 'unit' => 'L', 'name' => 'Surgical Guide Resin', 'name_ar' => 'راتنج الدليل الجراحي', 'description' => 'Biocompatible dental resin for surgical guide workflows.', 'sku' => 'FORMLABS-SURGICAL-GUIDE-1L', 'variant_name' => 'Surgical Guide Resin (Form 4), 1 L', 'variant_name_ar' => 'راتنج الدليل الجراحي (فورم 4)، 1 لتر', 'track_serials' => false, 'track_expiry' => true],
        ['brand' => 'FORMLABS', 'category' => 'post_processing', 'unit' => 'EA', 'name' => 'Form Wash V2', 'name_ar' => 'فورم واش الإصدار 2', 'description' => 'Automated washing unit for Formlabs printed parts.', 'sku' => 'FORMLABS-FORM-WASH-V2', 'variant_name' => 'Form Wash V2', 'variant_name_ar' => 'فورم واش الإصدار 2', 'track_serials' => true, 'track_expiry' => false],
        ['brand' => 'DENTSPLY-SIRONA', 'category' => 'printers', 'unit' => 'EA', 'name' => 'Primeprint Solution', 'name_ar' => 'حل برايم برنت', 'description' => 'Automated end-to-end medical-grade dental 3D printing solution.', 'sku' => 'DENTSPLY-PRIMEPRINT-SOLUTION', 'variant_name' => 'Primeprint Solution', 'variant_name_ar' => 'حل برايم برنت', 'track_serials' => true, 'track_expiry' => false],
        ['brand' => 'DENTSPLY-SIRONA', 'category' => 'post_processing', 'unit' => 'EA', 'name' => 'Primeprint PPU', 'name_ar' => 'وحدة برايم برنت للمعالجة اللاحقة', 'description' => 'Post-processing unit in the Primeprint dental 3D printing workflow.', 'sku' => 'DENTSPLY-PRIMEPRINT-PPU', 'variant_name' => 'Primeprint Post-Processing Unit', 'variant_name_ar' => 'وحدة المعالجة اللاحقة برايم برنت', 'track_serials' => true, 'track_expiry' => false],
        ['brand' => 'IVOCLAR', 'category' => 'printers', 'unit' => 'EA', 'name' => 'PrograPrint PR5', 'name_ar' => 'بروغرا برنت بي آر 5', 'description' => 'Dental 3D printer designed for dental technology workflows.', 'sku' => 'IVOCLAR-PROGRAPRINT-PR5', 'variant_name' => 'PrograPrint PR5', 'variant_name_ar' => 'بروغرا برنت بي آر 5', 'track_serials' => true, 'track_expiry' => false],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedDentalCatalogue();
        });
    }

    private function seedDentalCatalogue(): void
    {
        $this->removeLegacyDemoData();

        $units = $this->seedUnits();
        $categories = $this->seedCategories();
        $brands = $this->seedBrands();

        foreach (self::Products as $product) {
            $this->seedProduct($product, $brands, $categories, $units);
        }
    }

    /** @return array<string, Unit> */
    private function seedUnits(): array
    {
        $units = [];

        foreach (self::Units as $unit) {
            $units[$unit['symbol']] = Unit::query()->updateOrCreate(
                ['symbol' => $unit['symbol']],
                [...$unit, 'is_active' => true],
            );
        }

        return $units;
    }

    /** @return array<string, ProductCategory> */
    private function seedCategories(): array
    {
        $categories = [];

        foreach (self::Categories as $key => $category) {
            $categories[$key] = ProductCategory::query()->updateOrCreate(
                ['name' => $category['name']],
                [...$category, 'is_active' => true],
            );
        }

        return $categories;
    }

    /** @return array<string, Brand> */
    private function seedBrands(): array
    {
        $brands = [];

        foreach (self::Brands as $code => $brand) {
            $brands[$code] = Brand::query()->updateOrCreate(
                ['code' => $code],
                [...$brand, 'is_active' => true],
            );
        }

        return $brands;
    }

    /**
     * @param  ProductDefinition  $definition
     * @param  array<string, Brand>  $brands
     * @param  array<string, ProductCategory>  $categories
     * @param  array<string, Unit>  $units
     */
    private function seedProduct(array $definition, array $brands, array $categories, array $units): void
    {
        $brand = $brands[$definition['brand']];
        $category = $categories[$definition['category']];
        $unit = $units[$definition['unit']];

        $product = Product::query()->updateOrCreate(
            ['name' => $definition['name'], 'brand_id' => $brand->getKey()],
            ['name_ar' => $definition['name_ar'], 'description' => $definition['description'], 'category_id' => $category->getKey(), 'status' => 'active', 'is_active' => true],
        );

        ProductVariant::query()->updateOrCreate(
            ['sku' => $definition['sku']],
            ['product_id' => $product->getKey(), 'name' => $definition['variant_name'], 'name_ar' => $definition['variant_name_ar'], 'unit_id' => $unit->getKey(), 'track_serials' => $definition['track_serials'], 'track_expiry' => $definition['track_expiry'], 'status' => 'active', 'is_active' => true],
        );
    }

    private function removeLegacyDemoData(): void
    {
        $variantIds = ProductVariant::query()->withTrashed()->where('sku', 'like', 'DEMO-%')->pluck('id');
        $warehouseIds = Warehouse::query()->withTrashed()->where('code', 'like', 'DEMO-%')->pluck('id');

        $this->removeDemoDocuments($warehouseIds);
        $this->removeDemoStock($variantIds, $warehouseIds);
        $this->removeDemoMasterData($variantIds, $warehouseIds);
    }

    /** @param Collection<array-key, mixed> $warehouseIds */
    private function removeDemoDocuments(Collection $warehouseIds): void
    {
        $adjustmentIds = InventoryAdjustment::query()->whereIn('warehouse_id', $warehouseIds)->pluck('id');
        $transferIds = StockTransfer::query()->whereIn('from_warehouse_id', $warehouseIds)->orWhereIn('to_warehouse_id', $warehouseIds)->pluck('id');

        InventoryAdjustmentItem::query()->whereIn('inventory_adjustment_id', $adjustmentIds)->delete();
        InventoryAdjustment::query()->withTrashed()->whereIn('id', $adjustmentIds)->forceDelete();
        StockTransferItem::query()->whereIn('stock_transfer_id', $transferIds)->delete();
        StockTransfer::query()->withTrashed()->whereIn('id', $transferIds)->forceDelete();
    }

    /**
     * @param  Collection<array-key, mixed>  $variantIds
     * @param  Collection<array-key, mixed>  $warehouseIds
     */
    private function removeDemoStock(Collection $variantIds, Collection $warehouseIds): void
    {
        InventoryMovement::query()->whereIn('product_variant_id', $variantIds)->orWhereIn('warehouse_id', $warehouseIds)->delete();
        InventoryStock::query()->whereIn('product_variant_id', $variantIds)->orWhereIn('warehouse_id', $warehouseIds)->delete();
    }

    /**
     * @param  Collection<array-key, mixed>  $variantIds
     * @param  Collection<array-key, mixed>  $warehouseIds
     */
    private function removeDemoMasterData(Collection $variantIds, Collection $warehouseIds): void
    {
        WarehouseLocation::query()->withTrashed()->whereIn('warehouse_id', $warehouseIds)->forceDelete();
        ProductVariant::query()->withTrashed()->whereIn('id', $variantIds)->forceDelete();
        Product::query()->withTrashed()->where('name', 'Demo Widget')->forceDelete();
        Warehouse::query()->withTrashed()->whereIn('id', $warehouseIds)->forceDelete();
    }
}
