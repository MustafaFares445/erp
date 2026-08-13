<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ManageProductMoveLines;
use App\Filament\Resources\Products\Pages\ManageProductQuantities;
use App\Filament\Resources\Products\Pages\ManageProductVariants;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductVariants\Pages\ManageProductVariantAttributeValues;
use App\Filament\Resources\ProductVariants\Pages\ManageProductVariants as ManageProductVariantsIndex;
use App\Filament\Resources\ProductVariants\Pages\ViewProductVariant;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function productRecordTabsManager(): User
{
    (new InventoryPermissionSeeder)->run();
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo([
        InventoryPermission::CatalogView->value,
        InventoryPermission::CatalogManage->value,
    ]);

    return $manager;
}

it('registers every product record tab as a deep-linkable page', function (): void {
    expect(array_keys(ProductResource::getPages()))->toContain(
        'view',
        'edit',
        'variants',
        'vendors',
        'quantities',
        'movements',
    );
});

test('every product-record tab has a scoped deep link and product relationships do not leak rows', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $warehouse = Warehouse::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create();
    InventoryMovement::factory()->for($variant)->for($warehouse)->create();
    $reference = SupplierProductReference::factory()->for($variant, 'productVariant')->create();

    $otherVariant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($otherVariant)->for($warehouse)->create();
    InventoryMovement::factory()->for($otherVariant)->for($warehouse)->create();
    SupplierProductReference::factory()->for($otherVariant, 'productVariant')->create();

    foreach (['view', 'edit', 'variants', 'vendors', 'quantities', 'movements'] as $page) {
        expect(ProductResource::getUrl($page, ['record' => $product]))->toContain('/products/'.$product->getRouteKey());
    }

    expect($product->variants()->pluck('id')->all())->toBe([$variant->getKey()])
        ->and($product->stocks()->count())->toBe(1)
        ->and($product->movements()->count())->toBe(1)
        ->and($product->supplierProductReferences()->pluck('supplier_product_references.id')->all())->toBe([$reference->getKey()]);
});

it('exposes attribute values from the product variant record', function (): void {
    expect(array_keys(ProductVariantResource::getPages()))->toContain('attributes');

    $variant = ProductVariant::factory()->create();

    expect(ProductVariantResource::getUrl('attributes', ['record' => $variant]))
        ->toContain('/product-variants/'.$variant->getRouteKey().'/attributes');
});

it('exposes a product variant create action in the page header', function (): void {
    (new InventoryPermissionSeeder)->run();
    $user = User::factory()->admin()->create();
    $user->givePermissionTo([
        InventoryPermission::CatalogView->value,
        InventoryPermission::CatalogManage->value,
    ]);
    $product = Product::factory()->create();

    Livewire::actingAs($user)
        ->test(ManageProductVariants::class, ['record' => $product->getRouteKey()])
        ->assertActionVisible('create');
});

it('renders the product edit page and updates catalog fields while syncing images', function (): void {
    $manager = productRecordTabsManager();
    $product = Product::factory()->create(['name' => 'Before update']);

    Livewire::actingAs($manager)
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['name' => 'After update'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->name)->toBe('After update');
});

it('disables the product type and shows the immutable helper text once the product has stock history', function (): void {
    $manager = productRecordTabsManager();
    $variant = ProductVariant::factory()->for(Product::factory()->create())->create();
    InventoryMovement::factory()->for($variant)->for(Warehouse::factory()->create())->create();
    $product = $variant->product;

    Livewire::actingAs($manager)
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSee(__('admin.inventory.product_type.errors.immutable'));
});

it('falls back to the base record update when handling a non-product model', function (): void {
    $manager = productRecordTabsManager();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create(['name' => 'Original variant name']);

    $editProduct = Livewire::actingAs($manager)
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->instance();

    $handleRecordUpdate = new ReflectionMethod(EditProduct::class, 'handleRecordUpdate');
    $handleRecordUpdate->invoke($editProduct, $variant, ['name' => 'Updated through fallback']);

    expect($variant->refresh()->name)->toBe('Updated through fallback');
});

it('renders the move lines and quantities relation pages directly', function (): void {
    $manager = productRecordTabsManager();
    $manager->givePermissionTo([
        InventoryPermission::StockView->value,
        InventoryPermission::MovementView->value,
    ]);
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $warehouse = Warehouse::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create();
    InventoryMovement::factory()->for($variant)->for($warehouse)->create();

    Livewire::actingAs($manager)
        ->test(ManageProductMoveLines::class, ['record' => $product->getRouteKey()])
        ->assertOk();

    Livewire::actingAs($manager)
        ->test(ManageProductQuantities::class, ['record' => $product->getRouteKey()])
        ->assertOk();
});

it('renders the variant attribute values relation page directly', function (): void {
    $manager = productRecordTabsManager();
    $variant = ProductVariant::factory()->create();
    $attribute = ProductAttribute::factory()->create();
    $attributeValue = $attribute->values()->create([
        'value' => 'Blue',
        'value_ar' => 'أزرق',
        'is_active' => true,
    ]);
    $variant->attributeAssignments()->create(['product_attribute_value_id' => $attributeValue->getKey()]);

    Livewire::actingAs($manager)
        ->test(ManageProductVariantAttributeValues::class, ['record' => $variant->getRouteKey()])
        ->assertOk()
        ->assertCanSeeTableRecords($variant->attributeAssignments);
});

it('exposes the product variant navigation label', function (): void {
    expect(ProductVariantResource::getNavigationLabel())->toBe(__('admin.resources.product_variants'));
});

it('builds sub navigation only for view, edit, and related-record pages', function (): void {
    $variant = ProductVariant::factory()->create();

    $viewVariant = new ViewProductVariant;
    $viewVariant->record = $variant;

    expect(ProductVariantResource::getRecordSubNavigation($viewVariant))->not->toBeEmpty();

    expect(ProductVariantResource::getRecordSubNavigation(new ManageProductVariantsIndex))->toBe([]);

    expect(ProductResource::getRecordSubNavigation(new ManageProductVariantsIndex))->toBe([]);
});
