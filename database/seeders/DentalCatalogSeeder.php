<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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

    private const array Attributes = [
        'technology' => ['name' => 'Print technology', 'values' => ['MSLA', 'DLP', 'Automated washing', 'UV post-curing']],
        'power_supply' => ['name' => 'Power supply', 'values' => ['110 V', '120 V', '230 V', '100-240 V']],
        'material' => ['name' => 'Material', 'values' => ['Precision Model Resin', 'Surgical Guide Resin', 'Primeprint Photopolymer', 'Cleaning Solution']],
        'color' => ['name' => 'Color', 'values' => ['Beige', 'Translucent', 'White', 'Clear']],
        'resin_volume' => ['name' => 'Resin volume', 'values' => ['1 L', '5 L', '7-15 L']],
        'compatibility' => ['name' => 'Compatible system', 'values' => ['Form 4B', 'Form 4BL', 'Form 3B', 'Primeprint', 'PrograPrint PR5']],
        'application' => ['name' => 'Application', 'values' => ['Diagnostic models', 'Surgical guides', 'Dental appliances', 'Post-processing']],
        'package' => ['name' => 'Package', 'values' => ['Basic package', 'Complete package', 'Premium package']],
        'build_volume' => ['name' => 'Build volume', 'values' => ['20.0 × 12.5 × 21.0 cm', '125.44 × 78.4 mm', '14.4 L wash bucket']],
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

    /** @var list<ProductDefinition> */
    private const array AdditionalVariants = [
        ['brand' => 'FORMLABS', 'category' => 'printers', 'unit' => 'EA', 'name' => 'Form 4B', 'name_ar' => 'Form 4B', 'description' => 'Dental MSLA 3D printer for models and biocompatible dental appliances.', 'sku' => 'FORMLABS-FORM-4B-120V', 'variant_name' => 'Form 4B Dental 3D Printer, 120V', 'variant_name_ar' => 'Form 4B Dental 3D Printer, 120V', 'track_serials' => true, 'track_expiry' => false],
        ['brand' => 'FORMLABS', 'category' => 'printers', 'unit' => 'EA', 'name' => 'Form 4B', 'name_ar' => 'Form 4B', 'description' => 'Dental MSLA 3D printer for models and biocompatible dental appliances.', 'sku' => 'FORMLABS-FORM-4B-PREMIUM-230V', 'variant_name' => 'Form 4B Premium Package, 230V', 'variant_name_ar' => 'Form 4B Premium Package, 230V', 'track_serials' => true, 'track_expiry' => false],
        ['brand' => 'FORMLABS', 'category' => 'materials', 'unit' => 'L', 'name' => 'Precision Model Resin', 'name_ar' => 'Precision Model Resin', 'description' => 'High-accuracy beige resin for restorative and diagnostic dental models on the Form 4 series.', 'sku' => 'FORMLABS-PRECISION-MODEL-5L', 'variant_name' => 'Precision Model Resin (Form 4), 5 L', 'variant_name_ar' => 'Precision Model Resin (Form 4), 5 L', 'track_serials' => false, 'track_expiry' => true],
        ['brand' => 'FORMLABS', 'category' => 'materials', 'unit' => 'L', 'name' => 'Surgical Guide Resin', 'name_ar' => 'Surgical Guide Resin', 'description' => 'Biocompatible dental resin for surgical guide workflows.', 'sku' => 'FORMLABS-SURGICAL-GUIDE-5L', 'variant_name' => 'Surgical Guide Resin (Form 4), 5 L', 'variant_name_ar' => 'Surgical Guide Resin (Form 4), 5 L', 'track_serials' => false, 'track_expiry' => true],
        ['brand' => 'FORMLABS', 'category' => 'post_processing', 'unit' => 'EA', 'name' => 'Form Wash V2', 'name_ar' => 'Form Wash V2', 'description' => 'Automated washing unit for Formlabs printed parts.', 'sku' => 'FORMLABS-FORM-WASH-V2-120V', 'variant_name' => 'Form Wash V2, 120V', 'variant_name_ar' => 'Form Wash V2, 120V', 'track_serials' => true, 'track_expiry' => false],
        ['brand' => 'DENTSPLY-SIRONA', 'category' => 'printers', 'unit' => 'EA', 'name' => 'Primeprint Solution', 'name_ar' => 'Primeprint Solution', 'description' => 'Automated end-to-end medical-grade dental 3D printing solution.', 'sku' => 'DENTSPLY-PRIMEPRINT-SOLUTION-110V', 'variant_name' => 'Primeprint Solution, 110V', 'variant_name_ar' => 'Primeprint Solution, 110V', 'track_serials' => true, 'track_expiry' => false],
        ['brand' => 'DENTSPLY-SIRONA', 'category' => 'post_processing', 'unit' => 'EA', 'name' => 'Primeprint PPU', 'name_ar' => 'Primeprint PPU', 'description' => 'Post-processing unit in the Primeprint dental 3D printing workflow.', 'sku' => 'DENTSPLY-PRIMEPRINT-PPU-230V', 'variant_name' => 'Primeprint Post-Processing Unit, 230V', 'variant_name_ar' => 'Primeprint Post-Processing Unit, 230V', 'track_serials' => true, 'track_expiry' => false],
        ['brand' => 'IVOCLAR', 'category' => 'printers', 'unit' => 'EA', 'name' => 'PrograPrint PR5', 'name_ar' => 'PrograPrint PR5', 'description' => 'Dental 3D printer designed for dental technology workflows.', 'sku' => 'IVOCLAR-PROGRAPRINT-PR5-100-240V', 'variant_name' => 'PrograPrint PR5, 100-240V', 'variant_name_ar' => 'PrograPrint PR5, 100-240V', 'track_serials' => true, 'track_expiry' => false],
    ];

    private const array VariantAttributes = [
        'FORMLABS-FORM-4B' => ['technology' => 'MSLA', 'power_supply' => '230 V', 'compatibility' => 'Form 4B', 'application' => 'Diagnostic models', 'package' => 'Complete package', 'build_volume' => '20.0 × 12.5 × 21.0 cm'],
        'FORMLABS-FORM-4B-120V' => ['technology' => 'MSLA', 'power_supply' => '120 V', 'compatibility' => 'Form 4B', 'application' => 'Diagnostic models', 'package' => 'Basic package', 'build_volume' => '20.0 × 12.5 × 21.0 cm'],
        'FORMLABS-FORM-4B-PREMIUM-230V' => ['technology' => 'MSLA', 'power_supply' => '230 V', 'compatibility' => 'Form 4B', 'application' => 'Dental appliances', 'package' => 'Premium package', 'build_volume' => '20.0 × 12.5 × 21.0 cm'],
        'FORMLABS-PRECISION-MODEL-1L' => ['technology' => 'MSLA', 'material' => 'Precision Model Resin', 'color' => 'Beige', 'resin_volume' => '1 L', 'compatibility' => 'Form 4B', 'application' => 'Diagnostic models'],
        'FORMLABS-PRECISION-MODEL-5L' => ['technology' => 'MSLA', 'material' => 'Precision Model Resin', 'color' => 'Beige', 'resin_volume' => '5 L', 'compatibility' => 'Form 4B', 'application' => 'Diagnostic models'],
        'FORMLABS-SURGICAL-GUIDE-1L' => ['technology' => 'MSLA', 'material' => 'Surgical Guide Resin', 'color' => 'Translucent', 'resin_volume' => '1 L', 'compatibility' => 'Form 4B', 'application' => 'Surgical guides'],
        'FORMLABS-SURGICAL-GUIDE-5L' => ['technology' => 'MSLA', 'material' => 'Surgical Guide Resin', 'color' => 'Translucent', 'resin_volume' => '5 L', 'compatibility' => 'Form 4BL', 'application' => 'Surgical guides'],
        'FORMLABS-FORM-WASH-V2' => ['technology' => 'Automated washing', 'material' => 'Cleaning Solution', 'resin_volume' => '7-15 L', 'compatibility' => 'Form 4B', 'application' => 'Post-processing', 'build_volume' => '14.4 L wash bucket'],
        'FORMLABS-FORM-WASH-V2-120V' => ['technology' => 'Automated washing', 'power_supply' => '120 V', 'material' => 'Cleaning Solution', 'resin_volume' => '7-15 L', 'compatibility' => 'Form 4B', 'application' => 'Post-processing', 'build_volume' => '14.4 L wash bucket'],
        'DENTSPLY-PRIMEPRINT-SOLUTION' => ['technology' => 'DLP', 'power_supply' => '230 V', 'material' => 'Primeprint Photopolymer', 'color' => 'White', 'compatibility' => 'Primeprint', 'application' => 'Dental appliances', 'package' => 'Complete package'],
        'DENTSPLY-PRIMEPRINT-SOLUTION-110V' => ['technology' => 'DLP', 'power_supply' => '110 V', 'material' => 'Primeprint Photopolymer', 'color' => 'White', 'compatibility' => 'Primeprint', 'application' => 'Dental appliances', 'package' => 'Complete package'],
        'DENTSPLY-PRIMEPRINT-PPU' => ['technology' => 'UV post-curing', 'power_supply' => '230 V', 'color' => 'White', 'compatibility' => 'Primeprint', 'application' => 'Post-processing'],
        'DENTSPLY-PRIMEPRINT-PPU-230V' => ['technology' => 'UV post-curing', 'power_supply' => '230 V', 'color' => 'White', 'compatibility' => 'Primeprint', 'application' => 'Post-processing'],
        'IVOCLAR-PROGRAPRINT-PR5' => ['technology' => 'DLP', 'power_supply' => '100-240 V', 'color' => 'White', 'compatibility' => 'PrograPrint PR5', 'application' => 'Dental appliances', 'build_volume' => '125.44 × 78.4 mm'],
        'IVOCLAR-PROGRAPRINT-PR5-100-240V' => ['technology' => 'DLP', 'power_supply' => '100-240 V', 'color' => 'White', 'compatibility' => 'PrograPrint PR5', 'application' => 'Dental appliances', 'build_volume' => '125.44 × 78.4 mm'],
    ];

    private const array ProductImageUrls = [
        'Form 4B' => ['https://formlabs-media.formlabs.com/filer_public_thumbnails/filer_public/52/79/5279f8ec-c4dd-4cde-8827-8ab9780abff4/form4bseomedical.jpg__640x0_q85_subsampling-2.jpg'],
        'Precision Model Resin' => ['https://dental-media.formlabs.com/filer_public_thumbnails/filer_public/e4/8e/e48ed644-3d6f-4227-8b84-b92fed4a016c/07062026_precision_model_074-edit_no_shadow_store.jpg__640x0_q85_subsampling-2.jpg', 'https://dental-media.formlabs.com/filer_public/42/1b/421bd856-472e-4fa0-aabb-20eec730a37c/07062026_precision_model_074-edit_shadow_store.png'],
        'Surgical Guide Resin' => ['https://dental-media.formlabs.com/filer_public/01/cc/01ccfb57-196f-469f-b55b-5c4132118b63/surgical_guide_1.png'],
        'Form Wash V2' => ['https://dental-media.formlabs.com/filer_public/0e/57/0e57a174-b8f9-4143-80a9-fdc4b84a2b69/formlabs_wash_plus_front_ik_240314_store.png'],
        'Primeprint Solution' => ['https://career.dentsplysirona.com/content/dam/master/product-procedure-brand-categories/digital-dentistry/product-category/3d-printing/primeprint-3d-printer/images/PPS-Image-Primeprint-3d-Printing-Solution.jpg'],
        'Primeprint PPU' => ['https://career.dentsplysirona.com/content/dam/master/product-procedure-brand-categories/digital-dentistry/product-category/3d-printing/primeprint-3d-printer/images/PPS-Image-Primeprint-3d-Printing-Solution.jpg'],
        'PrograPrint PR5' => ['https://www.ivoclar.com/cache-buster-1/GLOBAL%20-%20MEDIA/Products/Digital%20Equipment/PrograPrint%20PR5/80336/image-thumb__80336__cms_teaser_1/PrograPrint-PR5_1920x1220px~-~media--8a2a7a85--query.08cc7dcb.jpg'],
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
        $attributeValues = $this->seedAttributes();

        foreach (self::Products as $product) {
            $seededProduct = $this->seedProduct($product, $brands, $categories, $units, $attributeValues);
            $this->seedProductImages($seededProduct);
        }

        foreach (self::AdditionalVariants as $variant) {
            $seededProduct = $this->seedProduct($variant, $brands, $categories, $units, $attributeValues);
            $this->seedProductImages($seededProduct);
        }
    }

    /** @return array<string, array<string, ProductAttributeValue>> */
    private function seedAttributes(): array
    {
        $attributeValues = [];

        foreach (self::Attributes as $code => $definition) {
            $attribute = ProductAttribute::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $definition['name'], 'data_type' => 'select', 'is_active' => true],
            );

            foreach ($definition['values'] as $attributeValueText) {
                $attributeValues[$code][$attributeValueText] = ProductAttributeValue::query()->updateOrCreate(
                    ['product_attribute_id' => $attribute->getKey(), 'value' => $attributeValueText],
                    ['is_active' => true],
                );
            }
        }

        return $attributeValues;
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
     * @param  array<string, array<string, ProductAttributeValue>>  $attributeValues
     */
    private function seedProduct(array $definition, array $brands, array $categories, array $units, array $attributeValues): Product
    {
        $brand = $brands[$definition['brand']];
        $category = $categories[$definition['category']];
        $unit = $units[$definition['unit']];

        $product = Product::query()->updateOrCreate(
            ['name' => $definition['name'], 'brand_id' => $brand->getKey()],
            ['name_ar' => $definition['name_ar'], 'description' => $definition['description'], 'category_id' => $category->getKey(), 'status' => 'active', 'is_active' => true],
        );

        $variant = ProductVariant::query()->updateOrCreate(
            ['sku' => $definition['sku']],
            ['product_id' => $product->getKey(), 'name' => $definition['variant_name'], 'name_ar' => $definition['variant_name_ar'], 'unit_id' => $unit->getKey(), 'track_serials' => $definition['track_serials'], 'track_expiry' => $definition['track_expiry'], 'status' => 'active', 'is_active' => true],
        );

        $this->seedVariantAttributes($variant, $attributeValues);

        return $product;
    }

    /** @param array<string, array<string, ProductAttributeValue>> $attributeValues */
    private function seedVariantAttributes(ProductVariant $variant, array $attributeValues): void
    {
        $variant->attributeAssignments()->delete();

        foreach (self::VariantAttributes[$variant->sku] ?? [] as $attributeCode => $attributeValueText) {
            $attributeValue = $attributeValues[$attributeCode][$attributeValueText] ?? null;

            if (! $attributeValue instanceof ProductAttributeValue) {
                throw new LogicException(sprintf('Unknown seeded attribute value [%s:%s].', $attributeCode, $attributeValueText));
            }

            ProductVariantAttributeValue::query()->create([
                'product_variant_id' => $variant->getKey(),
                'product_attribute_value_id' => $attributeValue->getKey(),
            ]);
        }
    }

    private function seedProductImages(Product $product): void
    {
        $imageUrls = self::ProductImageUrls[$product->name] ?? null;

        if ($imageUrls === null) {
            throw new LogicException(sprintf('No seeded image URLs configured for product [%s].', $product->name));
        }

        $media = $product->getMedia('images');

        if ($media->isNotEmpty() && $media->contains(static fn (Media $mediaItem): bool => $mediaItem->mime_type !== 'image/svg+xml')) {
            return;
        }

        if ($media->isNotEmpty()) {
            $product->clearMediaCollection('images');
        }

        foreach ($imageUrls as $position => $imageUrl) {
            $product->addMediaFromUrl($imageUrl, 'image/jpeg', 'image/png')
                ->withCustomProperties(['seeded_catalog_image' => true, 'source_url' => $imageUrl])
                ->usingName(sprintf('%s product image %d', $product->name, $position + 1))
                ->toMediaCollection('images');
        }
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
        ProductVariant::query()->withTrashed()->whereIn('id', $variantIds)->forceDelete();
        Product::query()->withTrashed()->where('name', 'Demo Widget')->forceDelete();
        Warehouse::query()->withTrashed()->whereIn('id', $warehouseIds)->forceDelete();
    }
}
