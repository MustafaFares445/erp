<?php

declare(strict_types=1);

use App\Filament\Resources\InventoryOperations\Schemas\OperationLinesRepeater;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Services\Inventory\ProductVariantUomService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns only active units configured for the selected variant', function (): void {
    $variant = ProductVariant::factory()->create();
    $baseUnit = Unit::query()->findOrFail($variant->unit_id);
    $baseUnit->update(['name' => 'Base unit']);

    $allowedUnit = Unit::factory()->create(['name' => 'Allowed unit']);
    $inactiveUnit = Unit::factory()->create(['name' => 'Inactive unit']);
    $unrelatedUnit = Unit::factory()->create(['name' => 'Unrelated unit']);

    app(ProductVariantUomService::class)->sync($variant, [
        variantUnitDefinition($baseUnit, isBase: true),
        variantUnitDefinition($allowedUnit, factor: '10'),
        variantUnitDefinition($inactiveUnit, factor: '5'),
    ]);

    $inactiveUnit->update(['is_active' => false]);

    $unitOptions = new ReflectionMethod(OperationLinesRepeater::class, 'unitOptions');
    $singleUnitId = new ReflectionMethod(OperationLinesRepeater::class, 'singleVariantUnitId');
    $unitOptionsResult = $unitOptions->invoke(null, productVariantKey($variant));
    $singleUnitIdResult = $singleUnitId->invoke(null, productVariantKey($variant));

    if (! is_array($unitOptionsResult) || ($singleUnitIdResult !== null && ! is_int($singleUnitIdResult))) {
        throw new LogicException('The product unit selection helpers must return the documented values.');
    }

    expect($unitOptionsResult)->toBe([
        productUnitId($allowedUnit) => 'Allowed unit',
        productUnitId($baseUnit) => 'Base unit',
    ]);
    expect(array_key_exists(productUnitId($inactiveUnit), $unitOptionsResult))->toBeFalse();
    expect(array_key_exists(productUnitId($unrelatedUnit), $unitOptionsResult))->toBeFalse();
    expect($singleUnitIdResult)->toBeNull();

    app(ProductVariantUomService::class)->sync($variant, [
        variantUnitDefinition($baseUnit, isBase: true),
    ]);

    expect($singleUnitId->invoke(null, productVariantKey($variant)))->toBe(productUnitId($baseUnit));
});

function productVariantKey(ProductVariant $variant): int
{
    $variantId = $variant->getKey();

    if (! is_int($variantId)) {
        throw new LogicException('A product variant fixture must have an integer ID.');
    }

    return $variantId;
}

function productUnitId(Unit $unit): int
{
    $unitId = $unit->getKey();

    if (! is_int($unitId)) {
        throw new LogicException('A unit fixture must have an integer ID.');
    }

    return $unitId;
}

/** @return array<string, bool|int|string> */
function variantUnitDefinition(Unit $unit, bool $isBase = false, string $factor = '1'): array
{
    return [
        'unit_id' => productUnitId($unit),
        'is_base' => $isBase,
        'is_purchase' => true,
        'is_sale' => true,
        'is_display' => $isBase,
        'factor_to_base' => $factor,
        'rounding_increment' => '0.001',
        'permits_cross_family_conversion' => false,
    ];
}
