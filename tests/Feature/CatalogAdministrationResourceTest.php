<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Pages\CatalogSetup;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('manages brands through the catalog setup page and resolves soft-deleted records for restoration', function (): void {
    $manager = catalogAdministrator();

    Livewire::actingAs($manager)
        ->test(CatalogSetup::class)
        ->call('setTab', 'brands')
        ->callAction(TestAction::make('create'), [
            'name' => 'Clinical Devices',
            'name_ar' => 'أجهزة سريرية',
            'code' => 'CLINICAL',
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $brand = Brand::query()->where('code', 'CLINICAL')->sole();

    Livewire::actingAs($manager)
        ->test(CatalogSetup::class)
        ->call('setTab', 'brands')
        ->callAction(TestAction::make('edit')->table($brand), [
            'name' => 'Clinical Systems',
            'name_ar' => $brand->name_ar,
            'code' => $brand->code,
            'is_active' => false,
        ])
        ->assertHasNoActionErrors()
        ->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$brand->fresh()]);

    $brand->delete();

    Livewire::actingAs($manager)
        ->test(CatalogSetup::class)
        ->call('setTab', 'brands')
        ->filterTable('trashed', 'with')
        ->assertCanSeeTableRecords([$brand]);

    expect(Brand::withTrashed()->find($brand->getKey()))->toBeInstanceOf(Brand::class);
});

it('manages hierarchical categories and units through the catalog setup page', function (): void {
    $manager = catalogAdministrator();
    $parent = ProductCategory::factory()->create(['name' => 'Equipment']);

    Livewire::actingAs($manager)
        ->test(CatalogSetup::class)
        ->call('setTab', 'categories')
        ->callAction(TestAction::make('create'), [
            'parent_id' => $parent->getKey(),
            'name' => 'Monitoring',
            'name_ar' => 'مراقبة',
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    Livewire::actingAs($manager)
        ->test(CatalogSetup::class)
        ->call('setTab', 'units')
        ->callAction(TestAction::make('create'), [
            'name' => 'Pack',
            'name_ar' => 'حزمة',
            'symbol' => 'PK',
            'allows_decimal' => false,
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $category = ProductCategory::query()->where('name', 'Monitoring')->sole();
    $unit = Unit::query()->where('symbol', 'PK')->sole();
    $category->delete();
    $unit->delete();

    expect($category->parent->is($parent))->toBeTrue()
        ->and(ProductCategory::withTrashed()->find($category->getKey()))->toBeInstanceOf(ProductCategory::class)
        ->and(Unit::withTrashed()->find($unit->getKey()))->toBeInstanceOf(Unit::class);
});

it('manages active product attributes and their select values through the catalog setup page', function (): void {
    $manager = catalogAdministrator();
    $attribute = ProductAttribute::query()->create([
        'name' => 'Color',
        'name_ar' => 'اللون',
        'code' => 'COLOR',
        'data_type' => 'select',
        'is_active' => true,
    ]);
    $attribute->values()->create([
        'value' => 'Blue',
        'value_ar' => 'أزرق',
        'is_active' => true,
    ]);

    Livewire::actingAs($manager)
        ->test(CatalogSetup::class)
        ->call('setTab', 'attributes')
        ->assertCanSeeTableRecords([$attribute])
        ->callAction(TestAction::make('edit')->table($attribute), [
            'name' => 'Finish',
            'name_ar' => 'Ù†Ù‡Ø§ÙŠØ©',
            'code' => 'COLOR',
            'data_type' => 'select',
            'is_active' => true,
            'values' => [[
                'value' => 'Red',
                'value_ar' => 'Ø£Ø­Ù…Ø±',
                'is_active' => true,
            ]],
        ])
        ->assertHasNoActionErrors();

    expect($attribute->refresh()->name)->toBe('Finish')
        ->and($attribute->values()->where('value', 'Red')->exists())->toBeTrue();

    $attribute->delete();

    expect($attribute->values()->where('value', 'Blue')->exists())->toBeTrue()
        ->and(ProductAttribute::withTrashed()->find($attribute->getKey()))->toBeInstanceOf(ProductAttribute::class);
});

it('ignores an unknown catalog tab without changing the current tab', function (): void {
    $manager = catalogAdministrator();

    Livewire::actingAs($manager)
        ->test(CatalogSetup::class)
        ->call('setTab', 'brands')
        ->call('setTab', 'not-a-real-tab')
        ->assertSet('tab', 'brands');
});

it('manages suppliers with product references', function (): void {
    $manager = catalogAdministrator();
    $variant = ProductVariant::factory()->create();
    $supplier = Supplier::query()->create([
        'name' => 'Levant Medical',
        'code' => 'LEV-MED',
        'email' => 'sales@example.test',
        'phone' => '+963111111',
        'is_active' => true,
        'address' => 'Damascus',
    ]);
    $supplier->productReferences()->create([
        'product_variant_id' => $variant->getKey(),
        'supplier_item_number' => 'SUP-100',
        'supplier_name' => 'Levant Medical',
        'country_code' => 'SY',
        'manufacturer' => 'Levant',
        'purchase_cost' => 15,
        'currency_code' => 'USD',
        'notes' => 'Primary source',
        'is_active' => true,
    ]);

    Livewire::actingAs($manager)
        ->test(ManageSuppliers::class)
        ->assertCanSeeTableRecords([$supplier]);

    $supplier->delete();

    expect($supplier->productReferences()->where('supplier_item_number', 'SUP-100')->exists())->toBeTrue()
        ->and(SupplierResource::getNavigationLabel())->toBe(__('admin.resources.suppliers'))
        ->and(SupplierResource::getRecordRouteBindingEloquentQuery()->find($supplier->getKey()))->toBeInstanceOf(Supplier::class);
});

it('builds nested catalog forms and creates products through their resource', function (): void {
    $manager = catalogAdministrator();
    $units = Unit::factory()->count(2)->create();

    Livewire::actingAs($manager)
        ->test(ManageProducts::class)
        ->callAction(TestAction::make('create'), [
            'name' => 'Patient monitor',
            'name_ar' => 'Patient monitor Arabic',
            'status' => 'active',
            'description' => 'Bedside monitoring product',
            'unit_ids' => $units->pluck('id')->all(),
            'default_unit_id' => $units->first()->getKey(),
        ])
        ->assertHasNoActionErrors();

    $product = Product::query()->where('name', 'Patient monitor')->sole();
    expect($product->units()->pluck('units.id')->all())->toBe([]);
    ProductVariant::factory()->for($product)->create();

    Livewire::actingAs($manager)
        ->test(ViewProduct::class, ['record' => $product->getRouteKey()])
        ->assertOk();

    $product->delete();

    expect(SupplierResource::form(Schema::make())->getComponents())->not->toBeEmpty()
        ->and(ProductResource::getGlobalSearchResultDetails($product))->toBe([
            'Brand' => 'No brand',
            'Category' => 'No category',
        ])
        ->and(ProductResource::getGlobalSearchResultDetails(new ProductVariant))->toBe([])
        ->and(ProductResource::getRecordRouteBindingEloquentQuery()->find($product->getKey()))->toBeInstanceOf(Product::class);
});

it('denies catalog administration without catalog permissions', function (): void {
    $administrator = User::factory()->admin()->create();

    $this->actingAs($administrator)
        ->get(CatalogSetup::getUrl())
        ->assertForbidden();
});

it('redirects every pre-consolidation catalog url to its tab on the catalog setup page', function (): void {
    $this->get('/admin/product-categories')->assertRedirect('/admin/catalog-setup?tab=categories');
    $this->get('/admin/brands')->assertRedirect('/admin/catalog-setup?tab=brands');
    $this->get('/admin/product-attributes')->assertRedirect('/admin/catalog-setup?tab=attributes');
    $this->get('/admin/units')->assertRedirect('/admin/catalog-setup?tab=units');

    $manager = catalogAdministrator();

    $this->actingAs($manager)
        ->get('/admin/brands')
        ->assertRedirect('/admin/catalog-setup?tab=brands');

    $this->actingAs($manager)
        ->get('/admin/catalog-setup?tab=brands')
        ->assertOk();
});

function catalogAdministrator(): User
{
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo([
        InventoryPermission::CatalogView->value,
        InventoryPermission::CatalogManage->value,
    ]);

    return $manager;
}
