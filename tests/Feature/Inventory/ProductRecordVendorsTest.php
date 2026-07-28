<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupplierProductReference;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a product vendors relationship contains only references for its variants', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $reference = SupplierProductReference::factory()->for($variant, 'productVariant')->create();
    SupplierProductReference::factory()->create();

    expect($product->supplierProductReferences()->pluck('supplier_product_references.id')->all())->toBe([$reference->getKey()]);
});

test('pricing fields stay unavailable without pricing-view permission', function (): void {
    (new InventoryPermissionSeeder)->run();
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(ProductVariantResource::canViewPricing())->toBeFalse();

    $user->givePermissionTo(InventoryPermission::PricingView->value);

    expect(ProductVariantResource::canViewPricing())->toBeTrue();
});
