<?php

declare(strict_types=1);

use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Inventory\ProductVariantUomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function uomCoverageDefinition(Unit $unit, bool $base = false, array $overrides = []): array
{
    return [
        'unit_id' => (int) $unit->getKey(),
        'is_base' => $base,
        'is_purchase' => true,
        'is_sale' => true,
        'is_display' => $base,
        'factor_to_base' => '1',
        'rounding_increment' => $unit->allows_decimal ? '0.001' : '1',
        'permits_cross_family_conversion' => false,
        'is_active' => true,
        ...$overrides,
    ];
}
function uomCoverageUnit(array $attributes = []): Unit
{
    return Unit::factory()->create([
        'family' => 'count',
        'allows_decimal' => false,
        'precision' => 0,
        ...$attributes,
    ]);
}

it('rejects invalid variant UOM definition shapes and identifiers', function (): void {
    $service = app(ProductVariantUomService::class);
    $variant = ProductVariant::factory()->create();
    $unit = uomCoverageUnit();

    expect(fn () => $service->sync(new ProductVariant, []))
        ->toThrow(LogicException::class, 'integer variant ID')
        ->and(fn () => $service->sync($variant, []))
        ->toThrow(ValidationException::class, 'At least one active unit')
        ->and(fn () => $service->sync($variant, ['bad']))
        ->toThrow(ValidationException::class, 'must name a unit')
        ->and(fn () => $service->sync($variant, [['unit_id' => 0]]))
        ->toThrow(ValidationException::class, 'configured only once')
        ->and(fn () => $service->sync($variant, [['unit_id' => 999999, 'is_base' => true, 'factor_to_base' => '1', 'rounding_increment' => '1']]))
        ->toThrow(ValidationException::class, 'must exist')
        ->and(fn () => $service->sync($variant, [uomCoverageDefinition($unit, true), uomCoverageDefinition($unit)]))
        ->toThrow(ValidationException::class, 'configured only once');
});
it('enforces base unit activation factor and configured unit activation', function (): void {
    $service = app(ProductVariantUomService::class);
    $variant = ProductVariant::factory()->create();
    $active = uomCoverageUnit();
    $inactive = uomCoverageUnit(['is_active' => false]);

    expect(fn () => $service->sync($variant, [uomCoverageDefinition($active)]))
        ->toThrow(ValidationException::class, 'Exactly one active base')
        ->and(fn () => $service->sync($variant, [
            uomCoverageDefinition($active, true),
            uomCoverageDefinition(uomCoverageUnit(), true),
        ]))->toThrow(ValidationException::class, 'Exactly one active base')
        ->and(fn () => $service->sync($variant, [uomCoverageDefinition($inactive, true)]))
        ->toThrow(ValidationException::class, 'base unit must be active')
        ->and(fn () => $service->sync($variant, [
            uomCoverageDefinition($active, true, ['factor_to_base' => '2']),
        ]))->toThrow(ValidationException::class, 'conversion factor of 1')
        ->and(fn () => $service->sync($variant, [
            uomCoverageDefinition($active, true),
            uomCoverageDefinition($inactive),
        ]))->toThrow(ValidationException::class, 'Every configured unit must be active');
});

it('rejects invalid booleans decimals and rounding precision', function (): void {
    $service = app(ProductVariantUomService::class);
    $variant = ProductVariant::factory()->create();
    $unit = uomCoverageUnit();

    expect(fn () => $service->sync($variant, [uomCoverageDefinition($unit, true, ['is_sale' => 'maybe'])]))
        ->toThrow(ValidationException::class, 'true or false')
        ->and(fn () => $service->sync($variant, [uomCoverageDefinition($unit, true, ['factor_to_base' => 1.5])]))
        ->toThrow(ValidationException::class, 'positive decimal')
        ->and(fn () => $service->sync($variant, [uomCoverageDefinition($unit, true, ['factor_to_base' => '0'])]))
        ->toThrow(ValidationException::class, 'at most six decimal places')
        ->and(fn () => $service->sync($variant, [uomCoverageDefinition($unit, true, ['rounding_increment' => '0.5'])]))
        ->toThrow(ValidationException::class, 'configured unit precision');
});
it('retires removed units demotes the old base and updates existing definitions', function (): void {
    $service = app(ProductVariantUomService::class);
    $variant = ProductVariant::factory()->create();
    $piece = uomCoverageUnit();
    $box = uomCoverageUnit();

    $service->sync($variant, [
        uomCoverageDefinition($piece, true),
        uomCoverageDefinition($box),
    ]);

    $updated = $service->sync($variant->refresh(), [
        uomCoverageDefinition($box, true, ['is_sale' => false]),
    ]);

    $pieceConfig = $updated->variantUnits()->where('unit_id', $piece->getKey())->firstOrFail();
    $boxConfig = $updated->variantUnits()->where('unit_id', $box->getKey())->firstOrFail();

    expect($pieceConfig->is_active)->toBeFalse()
        ->and($pieceConfig->is_base)->toBeFalse()
        ->and($pieceConfig->retired_at)->not->toBeNull()
        ->and($boxConfig->is_base)->toBeTrue()
        ->and($boxConfig->is_sale)->toBeFalse();
});
it('demotes the previous base unit when it remains configured but is no longer base', function (): void {
    $service = app(ProductVariantUomService::class);
    $variant = ProductVariant::factory()->create();
    $piece = uomCoverageUnit();
    $box = uomCoverageUnit();

    $service->sync($variant, [
        uomCoverageDefinition($piece, true),
        uomCoverageDefinition($box),
    ]);

    $updated = $service->sync($variant->refresh(), [
        uomCoverageDefinition($piece),
        uomCoverageDefinition($box, true),
    ]);

    $pieceConfig = $updated->variantUnits()->where('unit_id', $piece->getKey())->firstOrFail();
    $boxConfig = $updated->variantUnits()->where('unit_id', $box->getKey())->firstOrFail();

    expect($pieceConfig->is_base)->toBeFalse()
        ->and($pieceConfig->is_active)->toBeTrue()
        ->and($boxConfig->is_base)->toBeTrue();
});
it('protects ambiguous and changed base units after stock history exists', function (): void {
    $service = app(ProductVariantUomService::class);
    $variant = ProductVariant::factory()->create();
    $piece = uomCoverageUnit();
    $box = uomCoverageUnit();

    $service->sync($variant, [
        uomCoverageDefinition($piece, true),
        uomCoverageDefinition($box),
    ]);

    $warehouse = Warehouse::factory()->create();
    app(InventoryBalanceService::class)->receive($variant->refresh(), (int) $warehouse->getKey(), 1);

    expect(InventoryStock::query()->where('product_variant_id', $variant->getKey())->exists())->toBeTrue()
        ->and(fn () => $service->sync($variant->refresh(), [
            uomCoverageDefinition($box, true),
            uomCoverageDefinition($piece),
        ]))->toThrow(ValidationException::class, 'base unit cannot change');

    DB::table('product_variant_units')
        ->where('product_variant_id', $variant->getKey())
        ->update(['is_base' => false]);

    expect(fn () => $service->sync($variant->refresh(), [
        uomCoverageDefinition($piece, true),
        uomCoverageDefinition($box),
    ]))->toThrow(ValidationException::class, 'no unambiguous active base');
});
it('validates unit precision through the service boundary', function (): void {
    $service = app(ProductVariantUomService::class);
    $variant = ProductVariant::factory()->create();
    $unit = uomCoverageUnit();

    DB::table('units')->where('id', $unit->getKey())->update([
        'allows_decimal' => false,
        'precision' => 1,
    ]);

    expect(fn () => $service->sync($variant, [
        uomCoverageDefinition($unit->refresh(), true, ['rounding_increment' => '1']),
    ]))->toThrow(ValidationException::class, 'valid precision between zero and six');
});
