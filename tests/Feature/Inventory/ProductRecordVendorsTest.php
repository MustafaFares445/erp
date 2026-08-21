<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\Products\Pages\ManageProductVendors;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupplierProductReference;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a product vendors relationship contains only references for its variants', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $reference = SupplierProductReference::factory()->for($variant, 'productVariant')->create();
    SupplierProductReference::factory()->create();

    expect($product->supplierProductReferences()->pluck('supplier_product_references.id')->all())->toBe([$reference->getKey()]);
});

test('a product exposes its product-scoped pricing tiers', function (): void {
    $product = Product::factory()->create();
    $tier = PricingTier::factory()->productScoped()->create();
    $product->pricingTiers()->attach($tier);

    expect($product->pricingTiers()->pluck('pricing_tiers.id')->all())->toBe([$tier->getKey()]);
});

test('pricing fields stay unavailable without pricing-view permission', function (): void {
    (new InventoryPermissionSeeder)->run();
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(ProductVariantResource::canViewPricing())->toBeFalse();

    $user->givePermissionTo(InventoryPermission::PricingView->value);

    expect(ProductVariantResource::canViewPricing())->toBeTrue();
});

test('supplier references expose create and edit actions', function (): void {
    (new InventoryPermissionSeeder)->run();
    $user = User::factory()->admin()->create();
    $user->givePermissionTo([
        InventoryPermission::CatalogView->value,
        InventoryPermission::CatalogManage->value,
    ]);
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $reference = SupplierProductReference::factory()->for($variant, 'productVariant')->create();

    Livewire::actingAs($user)
        ->test(ManageProductVendors::class, ['record' => $product->getRouteKey()])
        ->assertActionVisible('create')
        ->assertActionVisible(TestAction::make('edit')->table($reference));
});
