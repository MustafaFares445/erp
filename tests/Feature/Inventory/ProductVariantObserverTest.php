<?php

declare(strict_types=1);

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies the tracking flags implied by the parent product type on save', function (): void {
    $variant = ProductVariant::factory()->expiryMaterial()->create([
        'track_serials' => false,
        'track_expiry' => false,
    ]);

    expect($variant->fresh())
        ->track_serials->toBeFalse()
        ->track_expiry->toBeTrue();
});

it('prefers an already-loaded product relation over issuing its own lookup', function (): void {
    $product = Product::factory()->machine()->create();
    $variant = ProductVariant::factory()->make([
        'product_id' => $product->id,
        'track_serials' => false,
        'track_expiry' => false,
    ]);
    $variant->setRelation('product', $product);

    $variant->save();

    expect($product->product_type)->toBe(ProductType::Machine)
        ->and($variant->fresh()->track_serials)->toBeTrue();
});

/**
 * The FK on `product_variants.product_id` restricts this from ever happening through a normal
 * write path — this exercises the observer's own defensive lookup when it cannot resolve a
 * type, confirming it steps aside quietly rather than crashing, and leaves the actual
 * referential-integrity violation to the database.
 */
it('leaves the tracking flags untouched and defers to the database when the parent product cannot be resolved', function (): void {
    $variant = ProductVariant::factory()->make([
        'product_id' => 999999,
        'track_serials' => false,
        'track_expiry' => false,
    ]);

    expect(fn () => $variant->save())->toThrow(QueryException::class);
});
