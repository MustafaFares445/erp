<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Services\Inventory\CountryNameResolver;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('finds products and variants through every catalog search field', function (): void {
    $viewer = catalogSearchViewer();
    $this->actingAs($viewer);

    $brand = Brand::query()->create([
        'name' => 'Northstar Brand',
        'name_ar' => 'علامة الشمال',
        'code' => 'NORTH',
    ]);
    $category = ProductCategory::query()->create([
        'name' => 'Diagnostic Devices',
        'name_ar' => 'أجهزة التشخيص',
    ]);
    $product = Product::factory()->create([
        'name' => 'Pulse Analyzer',
        'name_ar' => 'محلل النبض',
        'brand_id' => $brand->getKey(),
        'category_id' => $category->getKey(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'SKU-GLOBAL-900',
        'barcode' => 'BAR-998877',
        'name' => 'Portable Edition',
        'name_ar' => 'الإصدار المحمول',
    ]);
    $supplier = Supplier::query()->create([
        'name' => 'Levant Medical Supply',
        'code' => 'LEVANT',
    ]);
    SupplierProductReference::query()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'supplier_name' => 'Regional Partner',
        'supplier_item_number' => 'SUP-ITEM-42',
        'country_code' => 'SY',
        'manufacturer' => 'Precision Works',
        'currency_code' => 'USD',
    ]);

    foreach ([
        'Pulse Analyzer',
        'محلل النبض',
        'Northstar Brand',
        'علامة الشمال',
        'Diagnostic Devices',
        'أجهزة التشخيص',
        'SKU-GLOBAL-900',
        'BAR-998877',
        'Levant Medical Supply',
        'Regional Partner',
        'SUP-ITEM-42',
        'Precision Works',
        'Syria',
        'سوريا',
        'SY',
    ] as $term) {
        expect(ProductResource::getGlobalSearchResults($term))
            ->toHaveCount(1, "Product search failed for [{$term}]");
        expect(ProductVariantResource::getGlobalSearchResults($term))
            ->toHaveCount(1, "Variant search failed for [{$term}]");
    }

    $productResult = ProductResource::getGlobalSearchResults('Pulse Analyzer')->first();
    $variantResult = ProductVariantResource::getGlobalSearchResults('SKU-GLOBAL-900')->first();

    expect($productResult?->url)->toBe(ProductResource::getUrl('view', ['record' => $product]))
        ->and($variantResult?->url)->toBe(ProductVariantResource::getUrl('view', ['record' => $variant]));
});

it('resolves localized country names and ISO codes without a composer dependency', function (): void {
    $resolver = app(CountryNameResolver::class);

    expect($resolver->matchingCodes('SY'))->toContain('SY')
        ->and($resolver->matchingCodes('Syria'))->toContain('SY')
        ->and($resolver->matchingCodes('سوريا'))->toContain('SY');
});

it('does not expose catalog global search without catalog view permission', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    expect(ProductResource::canGloballySearch())->toBeFalse()
        ->and(ProductVariantResource::canGloballySearch())->toBeFalse();
});

function catalogSearchViewer(): User
{
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::CatalogView->value);

    return $viewer;
}
