<?php

declare(strict_types=1);

use App\Enums\ProductType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\ProductTypeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('derives the variant tracking flags from the product type on every save', function (
    ProductType $type,
    bool $serials,
    bool $expiry,
): void {
    $product = Product::factory()->create(['product_type' => $type]);

    // Deliberately contradicts the type, to prove the flags are not caller-controlled.
    $variant = ProductVariant::factory()->for($product)->create([
        'track_serials' => ! $serials,
        'track_expiry' => ! $expiry,
    ]);

    expect($variant->refresh()->track_serials)->toBe($serials)
        ->and($variant->track_expiry)->toBe($expiry)
        ->and($variant->productType())->toBe($type);
})->with([
    'machine' => [ProductType::Machine, true, false],
    'expiry material' => [ProductType::ExpiryMaterial, false, true],
    'grain' => [ProductType::Grain, false, false],
]);

it('refuses to re-type a product once it has stock history', function (): void {
    $variant = ProductVariant::factory()->machine()->create();
    $product = $variant->product;

    expect($product->hasStockHistory())->toBeFalse();

    // Retyping is fine while nothing has physically happened.
    $product->update(['product_type' => ProductType::Grain]);
    expect($product->refresh()->product_type)->toBe(ProductType::Grain);

    InventoryMovement::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => Warehouse::factory()->create()->getKey(),
    ]);

    expect($product->refresh()->hasStockHistory())->toBeTrue();

    $product->update(['product_type' => ProductType::Machine]);
})->throws(ValidationException::class);

it('leaves an unrelated update untouched on a product that has stock history', function (): void {
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    InventoryMovement::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => Warehouse::factory()->create()->getKey(),
    ]);

    $product = $variant->product;
    $product->update(['name' => 'Renamed but still the same type']);

    expect($product->refresh()->name)->toBe('Renamed but still the same type')
        ->and($product->product_type)->toBe(ProductType::ExpiryMaterial);
});

it('scopes products and variants by type', function (): void {
    $machine = ProductVariant::factory()->machine()->create();
    $grain = ProductVariant::factory()->grain()->create();

    expect(Product::query()->ofType(ProductType::Machine)->pluck('id')->all())
        ->toBe([$machine->product_id])
        ->and(ProductVariant::query()->ofProductType(ProductType::Grain)->pluck('id')->all())
        ->toBe([$grain->getKey()]);
});

it('derives the total weight a grain quantity represents', function (): void {
    $grain = ProductVariant::factory()->grain()->create(['net_weight' => 25]);
    $machine = ProductVariant::factory()->machine()->create();

    expect($grain->weightFor(3.5))->toBe(87.5)
        ->and($machine->weightFor(3.0))->toBeNull();
});

describe('the product type guard', function (): void {
    it('rejects a fractional machine quantity but accepts a fractional grain quantity', function (): void {
        $guard = app(ProductTypeGuard::class);
        $machine = ProductVariant::factory()->machine()->create();
        $grain = ProductVariant::factory()->grain()->create();

        $guard->assertQuantity($grain, 2.5);
        $guard->assertQuantity($machine, 2.0);

        expect(fn () => $guard->assertQuantity($machine, 2.5))
            ->toThrow(DomainException::class)
            ->and(fn () => $guard->assertQuantity($grain, 0.0))
            ->toThrow(DomainException::class);
    });

    it('rejects a fractional quantity for any type when the unit forbids decimals', function (): void {
        $guard = app(ProductTypeGuard::class);
        $grain = ProductVariant::factory()->grain()->create([
            'unit_id' => Unit::factory()->whole()->create()->getKey(),
        ]);

        expect(fn () => $guard->assertQuantity($grain->fresh(), 1.5))->toThrow(DomainException::class);
    });

    it('requires a decimal unit and a complete weight for a grain only', function (): void {
        $guard = app(ProductTypeGuard::class);
        $wholeUnit = Unit::factory()->whole()->create();

        $grainWithWholeUnit = ProductVariant::factory()->grain()->create(['unit_id' => $wholeUnit->getKey()]);
        $grainWithoutWeight = ProductVariant::factory()->grain()->create([
            'net_weight' => null,
            'weight_unit_id' => null,
        ]);
        $machine = ProductVariant::factory()->machine()->create();

        expect(fn () => $guard->assertUnitSuitsType($grainWithWholeUnit->fresh()))
            ->toThrow(DomainException::class)
            ->and(fn () => $guard->assertWeightIsComplete($grainWithoutWeight->fresh()))
            ->toThrow(DomainException::class);

        // A machine is measured in whole units and carries no weight, so neither rule applies.
        $guard->assertUnitSuitsType($machine);
        $guard->assertWeightIsComplete($machine);
    });

    it('requires an inbound expiry date for an expiry material and forbids one elsewhere', function (): void {
        $guard = app(ProductTypeGuard::class);
        $material = ProductVariant::factory()->expiryMaterial()->create();
        $grain = ProductVariant::factory()->grain()->create();

        $guard->assertInboundExpiry($material, today()->addMonth());
        $guard->assertInboundExpiry($material, today());
        $guard->assertInboundExpiry($grain, null);

        expect(fn () => $guard->assertInboundExpiry($material, null))
            ->toThrow(DomainException::class)
            ->and(fn () => $guard->assertInboundExpiry($material, today()->subDay()))
            ->toThrow(DomainException::class)
            ->and(fn () => $guard->assertInboundExpiry($grain, today()->addMonth()))
            ->toThrow(DomainException::class);
    });

    it('requires one serial per unit for a machine and none for other types', function (): void {
        $guard = app(ProductTypeGuard::class);
        $machine = ProductVariant::factory()->machine()->create();
        $grain = ProductVariant::factory()->grain()->create();

        $guard->assertSerialCoverage($machine, 2, 2.0);
        $guard->assertSerialCoverage($grain, 0, 12.5);

        expect(fn () => $guard->assertSerialCoverage($machine, 1, 2.0))
            ->toThrow(DomainException::class)
            ->and(fn () => $guard->assertSerialCoverage($machine, 2, 2.5))
            ->toThrow(DomainException::class)
            ->and(fn () => $guard->assertSerialCoverage($grain, 1, 1.0))
            ->toThrow(DomainException::class);
    });
});
