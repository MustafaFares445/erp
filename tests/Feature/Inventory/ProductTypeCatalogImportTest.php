<?php

declare(strict_types=1);

use App\Enums\ProductType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\CatalogImportCatalogService;
use App\Services\Inventory\CatalogImportValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function importCatalogRow(array $payload): ProductVariant
{
    [$variant] = app(CatalogImportCatalogService::class)->apply($payload, User::factory()->create());

    return $variant;
}

it('offers the product type and grain weight columns in the import template', function (): void {
    expect(app(CatalogImportValidator::class)->templateColumns())
        ->toContain('product_type', 'net_weight', 'weight_unit_symbol')
        // The legacy flag columns stay, because files written before product types must import.
        ->toContain('track_serials', 'track_expiry');
});

it('takes an explicit product type from the row', function (): void {
    $variant = importCatalogRow([
        'sku' => 'IMPORT-MACHINE',
        'product_name' => 'Imported machine',
        'variant_name' => 'Imported machine unit',
        'product_type' => ProductType::Machine->value,
    ]);

    expect($variant->product?->product_type)->toBe(ProductType::Machine)
        ->and($variant->track_serials)->toBeTrue()
        ->and($variant->track_expiry)->toBeFalse();
});

it('derives the type from the legacy tracking flags when the row states none', function (
    array $payload,
    ProductType $expected,
): void {
    $variant = importCatalogRow([
        'sku' => 'IMPORT-DERIVED-'.$expected->value,
        'product_name' => 'Derived '.$expected->value,
        'variant_name' => 'Derived variant',
        ...$payload,
    ]);

    expect($variant->product?->product_type)->toBe($expected)
        ->and($variant->track_serials)->toBe($expected->tracksSerials())
        ->and($variant->track_expiry)->toBe($expected->tracksExpiry());
})->with([
    'track_serials column' => [['track_serials' => 'true'], ProductType::Machine],
    'track_expiry column' => [['track_expiry' => 'true'], ProductType::ExpiryMaterial],
    'a serial number implies a machine' => [['serial_number' => 'SN-IMPORT-1'], ProductType::Machine],
    'a lot number implies an expiry material' => [['lot_number' => 'LOT-IMPORT-1'], ProductType::ExpiryMaterial],
]);

it('rejects an unrecognised product type', function (): void {
    $errors = app(CatalogImportValidator::class)->validate([
        'sku' => 'IMPORT-BAD-TYPE',
        'product_name' => 'Bad type',
        'variant_name' => 'Bad type variant',
        'product_type' => 'livestock',
    ], app(CatalogImportValidator::class)->activeAttributes());

    expect($errors['product_type'])->toContain('invalid');
});

it('rejects a non-positive grain net weight', function (): void {
    $validator = app(CatalogImportValidator::class);

    expect($validator->validate([
        'sku' => 'IMPORT-ZERO-WEIGHT',
        'product_name' => 'Zero weight',
        'variant_name' => 'Zero weight variant',
        'net_weight' => '0',
    ], $validator->activeAttributes())['net_weight'])->toContain('positive');
});

it('stores the grain weight and its unit from the row', function (): void {
    $variant = importCatalogRow([
        'sku' => 'IMPORT-GRAIN',
        'product_name' => 'Imported grain',
        'variant_name' => 'Imported grain sack',
        'product_type' => ProductType::Grain->value,
        'unit_symbol' => 'SACK',
        'allows_decimal' => 'true',
        'net_weight' => '25.5',
        'weight_unit_symbol' => 'KG',
    ]);

    expect((float) $variant->net_weight)->toBe(25.5)
        ->and($variant->weightUnit?->symbol)->toBe('KG')
        // A weight unit must permit fractions, so the import creates it that way.
        ->and($variant->weightUnit?->allows_decimal)->toBeTrue();
});

it('leaves an existing product type alone when a catalog-only row says nothing about it', function (): void {
    $existing = ProductVariant::factory()->machine()->create();
    $product = $existing->product;

    importCatalogRow([
        'sku' => 'IMPORT-SAME-PRODUCT',
        'product_name' => $product->name,
        'variant_name' => 'Another variant of the same product',
    ]);

    // Without this, a row that merely omits the flags would silently re-type a live machine.
    expect($product->refresh()->product_type)->toBe(ProductType::Machine);
});

it('refuses to re-type an imported product that already has stock history', function (): void {
    $variant = ProductVariant::factory()->machine()->create();
    InventoryMovement::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => Warehouse::factory()->create()->getKey(),
    ]);

    /** @var Product $product */
    $product = $variant->product;

    importCatalogRow([
        'sku' => $variant->sku,
        'product_name' => $product->name,
        'variant_name' => $variant->name,
        'product_type' => ProductType::Grain->value,
    ]);
})->throws(ValidationException::class);
