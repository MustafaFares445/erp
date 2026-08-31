<?php

declare(strict_types=1);

use App\Models\ProductVariant;
use App\Models\Unit;
use App\Services\Inventory\ProductVariantUomService;
use App\Services\Inventory\QuantityNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('normalizes a box quantity into a whole-piece base quantity exactly', function (): void {
    $piece = uomUnit(['code' => 'PIECE', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);
    $box = uomUnit(['code' => 'BOX', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);
    $variant = ProductVariant::factory()->create();

    configureVariantUoms($variant, [
        uomDefinition($piece, isBase: true, factor: '1', increment: '1'),
        uomDefinition($box, factor: '100', increment: '1'),
    ]);

    $normalized = app(QuantityNormalizer::class)->normalize($variant, uomUnitId($box), '5');

    expect($normalized->transactionQuantity)->toBe('5.000000')
        ->and($normalized->transactionUnitId)->toBe(uomUnitId($box))
        ->and($normalized->conversionFactorSnapshot)->toBe('100.000000')
        ->and($normalized->baseUnitId)->toBe(uomUnitId($piece))
        ->and($normalized->baseQuantity)->toBe('500.000000');
});

it('normalizes kilogram and explicit container conversions to a gram base quantity', function (): void {
    $gram = uomUnit(['code' => 'GRAM', 'family' => 'mass', 'precision' => 3]);
    $kilogram = uomUnit(['code' => 'KILOGRAM', 'family' => 'mass', 'precision' => 3]);
    $container = uomUnit(['code' => 'CONTAINER', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);
    $variant = ProductVariant::factory()->create();

    configureVariantUoms($variant, [
        uomDefinition($gram, isBase: true, factor: '1', increment: '0.001'),
        uomDefinition($kilogram, factor: '1000', increment: '0.001'),
        uomDefinition($container, factor: '25000', increment: '1', permitsCrossFamilyConversion: true),
    ]);

    $normalizer = app(QuantityNormalizer::class);

    expect($normalizer->normalize($variant, uomUnitId($kilogram), '25')->baseQuantity)->toBe('25000.000000')
        ->and($normalizer->normalize($variant, uomUnitId($container), '1')->baseQuantity)->toBe('25000.000000');
});

it('rejects unapproved cross-family conversions, invalid increments, precision loss, and floats', function (): void {
    $gram = uomUnit(['code' => 'GRAM', 'family' => 'mass', 'precision' => 3]);
    $container = uomUnit(['code' => 'CONTAINER', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);
    $variant = ProductVariant::factory()->create();

    expect(fn (): ProductVariant => configureVariantUoms($variant, [
        uomDefinition($gram, isBase: true, factor: '1', increment: '0.001'),
        uomDefinition($container, factor: '25000', increment: '1'),
    ]))->toThrow(ValidationException::class);

    $piece = uomUnit(['code' => 'PIECE', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);
    $halfPack = uomUnit(['code' => 'HALF-PACK', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);

    configureVariantUoms($variant, [
        uomDefinition($piece, isBase: true, factor: '1', increment: '1'),
        uomDefinition($halfPack, factor: '0.5', increment: '1'),
    ]);

    $normalizer = app(QuantityNormalizer::class);

    expect(fn (): mixed => $normalizer->normalize($variant, uomUnitId($halfPack), '1'))->toThrow(ValidationException::class)
        ->and(fn (): mixed => $normalizer->normalize($variant, uomUnitId($piece), 1.5))->toThrow(ValidationException::class);
});

/** @param array<string, mixed> $attributes */
function uomUnit(array $attributes): Unit
{
    return Unit::factory()->create([
        'name' => $attributes['code'],
        'symbol' => $attributes['code'],
        ...$attributes,
    ]);
}

function uomUnitId(Unit $unit): int
{
    $unitId = $unit->getKey();

    if (! is_int($unitId)) {
        throw new LogicException('A unit fixture must have an integer ID.');
    }

    return $unitId;
}

/**
 * @return array<string, mixed>
 */
function uomDefinition(
    Unit $unit,
    bool $isBase = false,
    string $factor = '1',
    string $increment = '0.001',
    bool $permitsCrossFamilyConversion = false,
): array {
    return [
        'unit_id' => uomUnitId($unit),
        'is_base' => $isBase,
        'is_purchase' => true,
        'is_sale' => true,
        'is_display' => $isBase,
        'factor_to_base' => $factor,
        'rounding_increment' => $increment,
        'permits_cross_family_conversion' => $permitsCrossFamilyConversion,
        'is_active' => true,
    ];
}

/** @param list<array<string, mixed>> $definitions */
function configureVariantUoms(ProductVariant $variant, array $definitions): ProductVariant
{
    return app(ProductVariantUomService::class)->sync($variant, $definitions);
}
