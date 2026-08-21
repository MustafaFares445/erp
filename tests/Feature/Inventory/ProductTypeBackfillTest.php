<?php

declare(strict_types=1);

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds the product type and grain weight columns without dropping anything', function (): void {
    expect(Schema::hasColumn('products', 'product_type'))->toBeTrue()
        ->and(Schema::hasColumns('product_variants', [
            'track_serials',
            'track_expiry',
            'net_weight',
            'weight_unit_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('inventory_operation_lines', ['inventory_lot_id', 'lot_number', 'expires_at']))->toBeTrue();
});

/**
 * The backfill's promise is that a product's assigned type implies exactly the tracking flags
 * its variants already carried, so no legacy row's behaviour changes. This reproduces
 * pre-migration rows by writing the flags straight to the database — bypassing the model, and
 * therefore the observer that would otherwise correct them — then re-runs the backfill.
 */
it('classifies legacy products from the tracking flags their variants already carry', function (
    bool $tracksSerials,
    bool $tracksExpiry,
    ProductType $expected,
): void {
    $variant = ProductVariant::factory()->create();

    DB::table('product_variants')->where('id', $variant->getKey())->update([
        'track_serials' => $tracksSerials,
        'track_expiry' => $tracksExpiry,
    ]);
    DB::table('products')->where('id', $variant->product_id)->update([
        'product_type' => ProductType::Grain->value,
    ]);

    runProductTypeBackfill();

    $product = Product::query()->findOrFail($variant->product_id);

    expect($product->product_type)->toBe($expected)
        // The decisive guarantee: the assigned type implies exactly the two legacy flags the
        // variant already had, so being classified changes nothing about how it is tracked.
        // Batch tracking is a later, independent flag those legacy rows never carried.
        ->and($expected->tracksSerials())->toBe($tracksSerials)
        ->and($expected->tracksExpiry())->toBe($tracksExpiry);
})->with([
    'serialized becomes a machine' => [true, false, ProductType::Machine],
    'expiring becomes an expiry material' => [false, true, ProductType::ExpiryMaterial],
    'untracked becomes a grain' => [false, false, ProductType::Grain],
]);

it('prefers the machine classification for a product whose variants disagree', function (): void {
    $product = Product::factory()->create();
    $serialized = ProductVariant::factory()->for($product)->create();
    $expiring = ProductVariant::factory()->for($product)->create();

    DB::table('product_variants')->where('id', $serialized->getKey())->update(['track_serials' => true]);
    DB::table('product_variants')->where('id', $expiring->getKey())->update(['track_expiry' => true]);

    runProductTypeBackfill();

    expect($product->refresh()->product_type)->toBe(ProductType::Machine);
});

it('is idempotent, so a re-run cannot drift a classification', function (): void {
    $variant = ProductVariant::factory()->create();
    DB::table('product_variants')->where('id', $variant->getKey())->update(['track_expiry' => true]);

    runProductTypeBackfill();
    $first = Product::query()->findOrFail($variant->product_id)->product_type;

    runProductTypeBackfill();
    $second = Product::query()->findOrFail($variant->product_id)->product_type;

    expect($first)->toBe(ProductType::ExpiryMaterial)->and($second)->toBe($first);
});

it('classifies a product with no variants as the default type', function (): void {
    $product = Product::factory()->create();
    DB::table('products')->where('id', $product->getKey())->update(['product_type' => ProductType::Machine->value]);

    runProductTypeBackfill();

    // No variant means no signal, so the backfill leaves the column's default in place.
    expect($product->refresh()->product_type)->toBe(ProductType::Machine);
});

function runProductTypeBackfill(): void
{
    $migration = require database_path('migrations/2026_08_03_150001_backfill_product_types_from_tracking_flags.php');
    $migration->up();
}
