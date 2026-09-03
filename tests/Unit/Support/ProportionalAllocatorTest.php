<?php

declare(strict_types=1);

use App\Support\ProportionalAllocator;
use DomainException;

it('floors ordinary proportional allocations and lets the final settlement absorb remainder', function (): void {
    $allocator = new ProportionalAllocator;

    $first = $allocator->allocate(
        totalMinor: 100,
        partMinor: 1,
        wholeMinor: 3,
    );
    $second = $allocator->allocate(
        totalMinor: 100,
        partMinor: 1,
        wholeMinor: 3,
        alreadyAllocatedMinor: $first,
    );
    $third = $allocator->allocate(
        totalMinor: 100,
        partMinor: 1,
        wholeMinor: 3,
        alreadyAllocatedMinor: $first + $second,
        settlesRemainder: true,
    );

    expect([$first, $second, $third])->toBe([33, 33, 34])
        ->and($first + $second + $third)->toBe(100);
});

it('never allocates more than the still-unallocated total', function (): void {
    $allocator = new ProportionalAllocator;

    expect($allocator->allocate(
        totalMinor: 101,
        partMinor: 80,
        wholeMinor: 100,
        alreadyAllocatedMinor: 90,
    ))->toBe(11);
});

it('rejects invalid negative amounts and a zero whole', function (array $arguments): void {
    $allocator = new ProportionalAllocator;

    expect(fn (): int => $allocator->allocate(...$arguments))
        ->toThrow(DomainException::class);
})->with([
    'negative total' => [[-1, 1, 1]],
    'negative part' => [[1, -1, 1]],
    'zero whole' => [[1, 1, 0]],
    'negative allocated' => [[1, 1, 1, -1]],
]);
