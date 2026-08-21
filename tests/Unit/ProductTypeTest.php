<?php

declare(strict_types=1);

use App\Enums\ProductType;

it('declares the tracking and measurement rules for each product type', function (
    ProductType $type,
    bool $serials,
    bool $expiry,
    bool $batches,
    bool $wholeQuantity,
    bool $weight,
): void {
    expect($type->tracksSerials())->toBe($serials)
        ->and($type->tracksExpiry())->toBe($expiry)
        ->and($type->tracksBatches())->toBe($batches)
        ->and($type->requiresWholeQuantity())->toBe($wholeQuantity)
        ->and($type->requiresWeight())->toBe($weight)
        ->and($type->trackingFlags())->toBe(['track_serials' => $serials, 'track_expiry' => $expiry, 'track_batches' => $batches]);
})->with([
    'machine' => [ProductType::Machine, true, false, false, true, false],
    'expiry material' => [ProductType::ExpiryMaterial, false, true, true, false, false],
    'grain' => [ProductType::Grain, false, false, true, false, true],
]);

it('classifies a type from the tracking flags a legacy variant already carries', function (
    bool $tracksSerials,
    bool $tracksExpiry,
    ProductType $expected,
): void {
    expect(ProductType::fromTrackingFlags($tracksSerials, $tracksExpiry))->toBe($expected);
})->with([
    'serialized' => [true, false, ProductType::Machine],
    'expiring' => [false, true, ProductType::ExpiryMaterial],
    'neither' => [false, false, ProductType::Grain],
    // Serial tracking wins, so a contradictory legacy row resolves the same way everywhere.
    'both' => [true, true, ProductType::Machine],
]);

it('round-trips its own classification for every type', function (ProductType $type): void {
    $flags = $type->trackingFlags();

    expect(ProductType::fromTrackingFlags($flags['track_serials'], $flags['track_expiry']))->toBe($type);
})->with(fn (): array => array_map(static fn (ProductType $type): array => [$type], ProductType::cases()));

it('exposes a translated label, description and badge colour for every type', function (ProductType $type): void {
    expect($type->label())->not->toContain('admin.inventory')
        ->and($type->description())->not->toContain('admin.inventory')
        ->and($type->color())->toBeIn(['info', 'warning', 'success'])
        ->and(ProductType::options())->toHaveKey($type->value);
})->with(fn (): array => array_map(static fn (ProductType $type): array => [$type], ProductType::cases()));

it('narrows filter values to the recognised product type values', function (): void {
    expect(ProductType::fromFilterValues(['machine', 'grain', 'bogus', 42, null]))
        ->toBe(['machine', 'grain']);
});

it('discards filter state that is not an array', function (mixed $values): void {
    expect(ProductType::fromFilterValues($values))->toBe([]);
})->with([
    'null' => [null],
    'string' => ['machine'],
    'int' => [1],
]);
