<?php

declare(strict_types=1);

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Inventory\ProductVariantUomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('keeps a transition-only product unit allow-list synchronized with variant UOMs', function (): void {
    $piece = variantUomUnit(['code' => 'PIECE', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);
    $box = variantUomUnit(['code' => 'BOX', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);
    $variant = ProductVariant::factory()->create();

    $configuredVariant = configureVariantUomsForVariantTest($variant, [
        variantUomDefinition($piece, isBase: true, factor: '1', increment: '1'),
        variantUomDefinition($box, factor: '100', increment: '1'),
    ]);

    $product = $configuredVariant->product;

    if (! $product instanceof Product) {
        throw new LogicException('A configured variant must have a product.');
    }

    expect($configuredVariant->unit_id)->toBe(variantUomUnitId($piece))
        ->and($configuredVariant->variantUnits()->where('is_base', true)->value('unit_id'))->toBe(variantUomUnitId($piece))
        ->and($product->units()->pluck('units.id')->all())
        ->toContain(variantUomUnitId($piece), variantUomUnitId($box));
});

it('locks the base unit and conversion metadata after a variant has stock history', function (): void {
    $piece = variantUomUnit(['code' => 'PIECE', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);
    $box = variantUomUnit(['code' => 'BOX', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);
    $variant = ProductVariant::factory()->create();

    $configuredVariant = configureVariantUomsForVariantTest($variant, [
        variantUomDefinition($piece, isBase: true, factor: '1', increment: '1'),
        variantUomDefinition($box, factor: '100', increment: '1'),
    ]);

    $warehouse = Warehouse::factory()->create();
    $warehouseId = $warehouse->getKey();

    if (! is_int($warehouseId)) {
        throw new LogicException('A warehouse fixture must have an integer ID.');
    }

    app(InventoryBalanceService::class)->receive($configuredVariant, $warehouseId, 1);

    expect(fn (): bool => $configuredVariant->update(['unit_id' => variantUomUnitId($box)]))->toThrow(ValidationException::class)
        ->and(fn (): bool => $configuredVariant->variantUnits()->where('unit_id', variantUomUnitId($box))->firstOrFail()->update(['factor_to_base' => '50']))->toThrow(ValidationException::class)
        ->and(fn (): bool => $piece->update(['family' => 'mass']))->toThrow(ValidationException::class);

    expect(InventoryStock::query()->where('product_variant_id', $configuredVariant->getKey())->value('on_hand_quantity'))->toBe('1.000000');
});

/** @param array<string, mixed> $attributes */
function variantUomUnit(array $attributes): Unit
{
    return Unit::factory()->create([
        'name' => $attributes['code'],
        'symbol' => $attributes['code'],
        ...$attributes,
    ]);
}

function variantUomUnitId(Unit $unit): int
{
    $unitId = $unit->getKey();

    if (! is_int($unitId)) {
        throw new LogicException('A unit fixture must have an integer ID.');
    }

    return $unitId;
}

/** @return array<string, mixed> */
function variantUomDefinition(Unit $unit, bool $isBase = false, string $factor = '1', string $increment = '0.001'): array
{
    return [
        'unit_id' => variantUomUnitId($unit),
        'is_base' => $isBase,
        'is_purchase' => true,
        'is_sale' => true,
        'is_display' => $isBase,
        'factor_to_base' => $factor,
        'rounding_increment' => $increment,
        'permits_cross_family_conversion' => false,
        'is_active' => true,
    ];
}

/** @param list<array<string, mixed>> $definitions */
function configureVariantUomsForVariantTest(ProductVariant $variant, array $definitions): ProductVariant
{
    return app(ProductVariantUomService::class)->sync($variant, $definitions);
}
