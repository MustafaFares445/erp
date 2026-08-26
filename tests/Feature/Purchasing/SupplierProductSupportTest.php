<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\SupplierProductSupport;
use App\Services\Purchasing\SupplierSupportResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prefers variant support over product-wide support', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $productSupplier = Supplier::factory()->create();
    $variantSupplier = Supplier::factory()->create();

    SupplierProductSupport::factory()->create([
        'supplier_id' => $productSupplier->getKey(),
        'product_id' => $product->getKey(),
        'product_variant_id' => null,
    ]);
    SupplierProductSupport::factory()->create([
        'supplier_id' => $variantSupplier->getKey(),
        'product_variant_id' => $variant->getKey(),
    ]);

    expect(app(SupplierSupportResolver::class)->eligibleSupplierIds([$variant->getKey()]))
        ->toBe([$variantSupplier->getKey()]);
});

it('returns only suppliers common to every selected variant', function (): void {
    $product = Product::factory()->create();
    $firstVariant = ProductVariant::factory()->for($product)->create();
    $secondVariant = ProductVariant::factory()->for($product)->create();
    $commonSupplier = Supplier::factory()->create();
    $otherSupplier = Supplier::factory()->create();

    foreach ([$firstVariant, $secondVariant] as $variant) {
        SupplierProductSupport::factory()->create([
            'supplier_id' => $commonSupplier->getKey(),
            'product_variant_id' => $variant->getKey(),
        ]);
    }

    SupplierProductSupport::factory()->create([
        'supplier_id' => $otherSupplier->getKey(),
        'product_variant_id' => $firstVariant->getKey(),
    ]);

    expect(app(SupplierSupportResolver::class)->eligibleSupplierIds([
        $firstVariant->getKey(),
        $secondVariant->getKey(),
    ]))->toBe([$commonSupplier->getKey()]);
});

it('backfills active supplier product references as variant support', function (): void {
    $reference = SupplierProductReference::factory()->create(['is_active' => true]);

    $this->artisan('purchasing:backfill-supplier-product-supports')
        ->assertSuccessful();

    expect(SupplierProductSupport::query()
        ->where('supplier_id', $reference->supplier_id)
        ->where('product_variant_id', $reference->product_variant_id)
        ->exists())->toBeTrue();
});
