<?php

declare(strict_types=1);

use App\Models\InventoryMovement;
use App\Models\ProductVariantUnit;
use App\Models\Unit;
use App\Observers\ProductVariantUnitObserver;
use App\Observers\UnitObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('applies unit defaults and rejects invalid precision and family values', function (): void {
    $observer = new UnitObserver;

    $decimal = new Unit;
    $decimal->allows_decimal = true;
    $decimal->precision = null;
    $decimal->family = null;
    $observer->saving($decimal);

    expect($decimal->precision)->toBe(3)
        ->and($decimal->family)->toBe('unspecified');

    $whole = new Unit;
    $whole->allows_decimal = false;
    $whole->precision = null;
    $whole->family = 'count';
    $observer->saving($whole);

    expect($whole->precision)->toBe(0);

    foreach ([
        ['allows_decimal' => true, 'precision' => -1, 'family' => 'count'],
        ['allows_decimal' => true, 'precision' => 7, 'family' => 'count'],
        ['allows_decimal' => false, 'precision' => 1, 'family' => 'count'],
    ] as $attributes) {
        $unit = new Unit;
        $unit->forceFill($attributes);

        expect(fn () => $observer->saving($unit))->toThrow(ValidationException::class);
    }

    $blankFamily = new Unit;
    $blankFamily->forceFill([
        'allows_decimal' => true,
        'precision' => 3,
        'family' => '   ',
    ]);

    expect(fn () => $observer->saving($blankFamily))->toThrow(ValidationException::class);
});

it('allows harmless unit updates when no stock history exists', function (): void {
    $observer = new UnitObserver;
    $unit = Unit::factory()->create();

    $observer->updating($unit);

    $unit->name = 'Renamed only';
    $observer->updating($unit);

    $unit->family = 'mass';
    $observer->updating($unit);

    expect($unit->family)->toBe('mass');
});

it('covers product variant unit decimal validation branches', function (): void {
    $observer = new ProductVariantUnitObserver;
    $positiveDecimal = new ReflectionMethod($observer, 'positiveDecimal');

    expect($positiveDecimal->invoke($observer, 2, 'factor'))->toBe('2')
        ->and($positiveDecimal->invoke($observer, '2.500000', 'factor'))->toBe('2.500000');

    foreach ([
        new stdClass,
        'not-a-number',
        '-1',
        '0',
        '1.0000001',
    ] as $invalid) {
        expect(fn (): mixed => $positiveDecimal->invoke($observer, $invalid, 'factor'))
            ->toThrow(ValidationException::class);
    }
});

it('covers product variant unit save and delete guards with and without history', function (): void {
    $observer = new ProductVariantUnitObserver;

    $unsaved = ProductVariantUnit::factory()->make([
        'is_base' => true,
        'factor_to_base' => '1.000000',
        'rounding_increment' => '0.001000',
    ]);
    $observer->saving($unsaved);

    $invalidBase = ProductVariantUnit::factory()->make([
        'is_base' => true,
        'factor_to_base' => '2.000000',
        'rounding_increment' => '0.001000',
    ]);
    expect(fn () => $observer->saving($invalidBase))->toThrow(ValidationException::class);

    $variantUnit = ProductVariantUnit::factory()->create([
        'is_base' => false,
        'factor_to_base' => '2.000000',
        'rounding_increment' => '0.001000',
    ]);

    $variantUnit->factor_to_base = '3.000000';
    $observer->saving($variantUnit);
    $observer->deleting($variantUnit);

    InventoryMovement::factory()->create([
        'product_variant_id' => $variantUnit->product_variant_id,
    ]);

    $variantUnit->factor_to_base = '4.000000';

    expect(fn () => $observer->saving($variantUnit))
        ->toThrow(ValidationException::class, 'cannot change after this variant has stock history')
        ->and(fn () => $observer->deleting($variantUnit))
        ->toThrow(ValidationException::class, 'must be retired instead of deleted');
});
