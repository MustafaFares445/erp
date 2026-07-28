<?php

declare(strict_types=1);

use App\Filament\Resources\Products\ProductResource;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupplierProductReference;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers every product record tab as a deep-linkable page', function (): void {
    expect(array_keys(ProductResource::getPages()))->toContain(
        'view',
        'edit',
        'attributes',
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

    foreach (['view', 'edit', 'attributes', 'variants', 'vendors', 'quantities', 'movements'] as $page) {
        expect(ProductResource::getUrl($page, ['record' => $product]))->toContain('/products/'.$product->getKey());
    }

    expect($product->variants()->pluck('id')->all())->toBe([$variant->getKey()])
        ->and($product->stocks()->count())->toBe(1)
        ->and($product->movements()->count())->toBe(1)
        ->and($product->supplierProductReferences()->pluck('supplier_product_references.id')->all())->toBe([$reference->getKey()]);
});
