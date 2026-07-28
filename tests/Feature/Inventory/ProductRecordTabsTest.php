<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\Products\Pages\ManageProductVariants;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
