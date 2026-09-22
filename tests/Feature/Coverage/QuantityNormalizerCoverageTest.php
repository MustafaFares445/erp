<?php

declare(strict_types=1);

use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Unit;
use App\Services\Inventory\QuantityNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function quantityNormalizerCoverageVariant(bool $whole = false): array
{
    $variant = $whole
        ? ProductVariant::factory()->machine()->create()
        : ProductVariant::factory()->create();
    $baseLink = $variant->variantUnits()->where('is_base', true)->firstOrFail();
    $baseUnit = $baseLink->unit()->firstOrFail();

    return [$variant, $baseLink, $baseUnit];
}

function quantityNormalizerCoverageLink(ProductVariant $variant, Unit $unit, array $overrides = []): ProductVariantUnit
{
    return ProductVariantUnit::factory()->for($variant, 'productVariant')->for($unit, 'unit')->create(array_merge([
        'is_base' => false,
        'factor_to_base' => '1.000000',
        'rounding_increment' => '0.001000',
        'is_active' => true,
    ], $overrides));
}

it('normalizes an active variant unit into its base quantity', function (): void {
    [$variant, $baseLink, $baseUnit] = quantityNormalizerCoverageVariant();

    $normalized = app(QuantityNormalizer::class)->normalize(
        $variant,
        (int) $baseUnit->getKey(),
        '2.000',
    );

    expect($normalized->transactionQuantity)->toBe('2.000000')
        ->and($normalized->transactionUnitId)->toBe($baseUnit->getKey())
        ->and($normalized->baseUnitId)->toBe($baseUnit->getKey())
        ->and($normalized->conversionFactorSnapshot)->toBe('1.000000')
        ->and($normalized->baseQuantity)->toBe('2.000000');
});

it('rejects malformed and non-positive exact quantities', function (): void {
    [$variant, , $baseUnit] = quantityNormalizerCoverageVariant();
    $service = app(QuantityNormalizer::class);

    foreach ([1.5, '-1', '0', '1.0000001', 'abc'] as $quantity) {
        expect(fn () => $service->normalize($variant, (int) $baseUnit->getKey(), $quantity))
            ->toThrow(ValidationException::class);
    }
});

it('rejects missing inactive and invalid base-unit configurations', function (): void {
    $service = app(QuantityNormalizer::class);

    [$missingBase, $missingBaseLink, $missingBaseUnit] = quantityNormalizerCoverageVariant();
    DB::table('product_variant_units')->where('id', $missingBaseLink->getKey())->update(['is_base' => false]);
    expect(fn () => $service->normalize($missingBase, (int) $missingBaseUnit->getKey(), '1'))
        ->toThrow(ValidationException::class);

    [$inactiveBase, , $inactiveBaseUnit] = quantityNormalizerCoverageVariant();
    DB::table('units')->where('id', $inactiveBaseUnit->getKey())->update(['is_active' => false]);
    expect(fn () => $service->normalize($inactiveBase, (int) $inactiveBaseUnit->getKey(), '1'))
        ->toThrow(ValidationException::class);

    [$factorBase, $factorBaseLink, $factorBaseUnit] = quantityNormalizerCoverageVariant();
    DB::table('product_variant_units')->where('id', $factorBaseLink->getKey())->update(['factor_to_base' => '2.000000']);
    expect(fn () => $service->normalize($factorBase, (int) $factorBaseUnit->getKey(), '1'))
        ->toThrow(ValidationException::class);
});

it('rejects an active transaction unit whose base unit has gone inactive', function (): void {
    [$variant, , $baseUnit] = quantityNormalizerCoverageVariant();
    DB::table('units')->where('id', $baseUnit->getKey())->update(['is_active' => false]);

    $transactionUnit = Unit::factory()->create();
    quantityNormalizerCoverageLink($variant, $transactionUnit);

    expect(fn () => app(QuantityNormalizer::class)->normalize($variant, (int) $transactionUnit->getKey(), '1'))
        ->toThrow(ValidationException::class);
});

it('rejects inactive transaction units and invalid precision definitions', function (): void {
    $service = app(QuantityNormalizer::class);

    [$variant] = quantityNormalizerCoverageVariant();
    $inactiveUnit = Unit::factory()->create(['is_active' => false]);
    quantityNormalizerCoverageLink($variant, $inactiveUnit);
    expect(fn () => $service->normalize($variant, (int) $inactiveUnit->getKey(), '1'))
        ->toThrow(ValidationException::class);

    [$precisionVariant, , $precisionUnit] = quantityNormalizerCoverageVariant();
    DB::table('units')->where('id', $precisionUnit->getKey())->update(['precision' => 7]);
    expect(fn () => $service->normalize($precisionVariant, (int) $precisionUnit->getKey(), '1'))
        ->toThrow(ValidationException::class);

    [$wholeVariant, , $wholeUnit] = quantityNormalizerCoverageVariant(true);
    DB::table('units')->where('id', $wholeUnit->getKey())->update(['precision' => 1]);
    expect(fn () => $service->normalize($wholeVariant, (int) $wholeUnit->getKey(), '1'))
        ->toThrow(ValidationException::class);
});

it('rejects quantity precision rounding increments and implicit cross-family conversions', function (): void {
    $service = app(QuantityNormalizer::class);

    [$precisionVariant, , $precisionUnit] = quantityNormalizerCoverageVariant();
    DB::table('units')->where('id', $precisionUnit->getKey())->update(['precision' => 2]);
    expect(fn () => $service->normalize($precisionVariant, (int) $precisionUnit->getKey(), '1.234'))
        ->toThrow(ValidationException::class);

    [$roundingVariant, $roundingLink, $roundingUnit] = quantityNormalizerCoverageVariant();
    DB::table('product_variant_units')->where('id', $roundingLink->getKey())->update(['rounding_increment' => '0.500000']);
    expect(fn () => $service->normalize($roundingVariant, (int) $roundingUnit->getKey(), '1.25'))
        ->toThrow(ValidationException::class);

    [$familyVariant] = quantityNormalizerCoverageVariant();
    $massUnit = Unit::factory()->weight()->create();
    quantityNormalizerCoverageLink($familyVariant, $massUnit, ['factor_to_base' => '2.000000']);
    expect(fn () => $service->normalize($familyVariant, (int) $massUnit->getKey(), '1'))
        ->toThrow(ValidationException::class);
});

it('rejects invalid conversion factors and rounding increments', function (): void {
    $service = app(QuantityNormalizer::class);

    [$factorVariant] = quantityNormalizerCoverageVariant();
    $factorUnit = Unit::factory()->create();
    $factorLink = quantityNormalizerCoverageLink($factorVariant, $factorUnit);
    DB::table('product_variant_units')->where('id', $factorLink->getKey())->update(['factor_to_base' => '0.000000']);
    expect(fn () => $service->normalize($factorVariant, (int) $factorUnit->getKey(), '1'))
        ->toThrow(ValidationException::class);

    [$incrementVariant] = quantityNormalizerCoverageVariant();
    $incrementUnit = Unit::factory()->create();
    $incrementLink = quantityNormalizerCoverageLink($incrementVariant, $incrementUnit);
    DB::table('product_variant_units')->where('id', $incrementLink->getKey())->update(['rounding_increment' => '0.000000']);
    expect(fn () => $service->normalize($incrementVariant, (int) $incrementUnit->getKey(), '1'))
        ->toThrow(ValidationException::class);

    [$incrementPrecisionVariant] = quantityNormalizerCoverageVariant();
    $incrementPrecisionUnit = Unit::factory()->create(['precision' => 2]);
    quantityNormalizerCoverageLink($incrementPrecisionVariant, $incrementPrecisionUnit, ['rounding_increment' => '0.001000']);
    expect(fn () => $service->normalize($incrementPrecisionVariant, (int) $incrementPrecisionUnit->getKey(), '1.00'))
        ->toThrow(ValidationException::class);
});

it('validates converted base precision and allows explicit cross-family conversion', function (): void {
    $service = app(QuantityNormalizer::class);

    [$wholeVariant] = quantityNormalizerCoverageVariant(true);
    $decimalCountUnit = Unit::factory()->create(['family' => 'count', 'precision' => 1, 'allows_decimal' => true]);
    quantityNormalizerCoverageLink($wholeVariant, $decimalCountUnit, [
        'factor_to_base' => '1.500000',
        'rounding_increment' => '0.100000',
    ]);
    expect(fn () => $service->normalize($wholeVariant, (int) $decimalCountUnit->getKey(), '1.0'))
        ->toThrow(ValidationException::class);

    [$crossVariant] = quantityNormalizerCoverageVariant();
    $massUnit = Unit::factory()->weight()->create();
    quantityNormalizerCoverageLink($crossVariant, $massUnit, [
        'factor_to_base' => '2.000000',
        'rounding_increment' => '0.001000',
        'permits_cross_family_conversion' => true,
    ]);
    $normalized = $service->normalize($crossVariant, (int) $massUnit->getKey(), '1.250');

    expect($normalized->baseQuantity)->toBe('2.500000')
        ->and($normalized->conversionFactorSnapshot)->toBe('2.000000');
});
