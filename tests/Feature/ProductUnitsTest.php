<?php

declare(strict_types=1);

use App\Filament\Resources\InventoryOperations\Schemas\OperationLinesRepeater;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns only active units assigned to the selected product', function (): void {
    $product = Product::factory()->create();
    $allowedUnit = Unit::factory()->create(['name' => 'Allowed unit']);
    $secondAllowedUnit = Unit::factory()->create(['name' => 'Second allowed unit']);
    $inactiveUnit = Unit::factory()->create(['name' => 'Inactive unit', 'is_active' => false]);
    $unrelatedUnit = Unit::factory()->create(['name' => 'Unrelated unit']);
    $product->syncUnits([productUnitId($allowedUnit), productUnitId($secondAllowedUnit), productUnitId($inactiveUnit)]);

    $unitOptions = new ReflectionMethod(OperationLinesRepeater::class, 'unitOptions');
    $singleUnitId = new ReflectionMethod(OperationLinesRepeater::class, 'singleUnitId');
    $unitOptionsResult = $unitOptions->invoke(null, productKey($product));
    $singleUnitIdResult = $singleUnitId->invoke(null, productKey($product));

    if (! is_array($unitOptionsResult) || ($singleUnitIdResult !== null && ! is_int($singleUnitIdResult))) {
        throw new LogicException('The product unit selection helpers must return the documented values.');
    }

    expect($unitOptionsResult)->toBe([
        productUnitId($allowedUnit) => 'Allowed unit',
        productUnitId($secondAllowedUnit) => 'Second allowed unit',
    ]);
    expect(array_key_exists(productUnitId($inactiveUnit), $unitOptionsResult))->toBeFalse();
    expect(array_key_exists(productUnitId($unrelatedUnit), $unitOptionsResult))->toBeFalse();
    expect($singleUnitIdResult)->toBeNull();

    $product->syncUnits([productUnitId($allowedUnit)]);

    expect($singleUnitId->invoke(null, productKey($product)))->toBe(productUnitId($allowedUnit));
});

function productKey(Product $product): int
{
    $productId = $product->getKey();

    if (! is_int($productId)) {
        throw new LogicException('A product fixture must have an integer ID.');
    }

    return $productId;
}

function productUnitId(Unit $unit): int
{
    $unitId = $unit->getKey();

    if (! is_int($unitId)) {
        throw new LogicException('A unit fixture must have an integer ID.');
    }

    return $unitId;
}
