<?php

declare(strict_types=1);

use App\Enums\WriteOffReason;

it('exposes labels for every controlled write-off reason', function (): void {
    expect(array_map(
        static fn (WriteOffReason $reason): string => $reason->value,
        WriteOffReason::cases(),
    ))->toBe([
        'insolvency',
        'untraceable',
        'disputed_and_abandoned',
        'time_barred',
        'commercially_uneconomic',
        'other',
    ]);

    foreach (WriteOffReason::cases() as $reason) {
        expect($reason->label())->toBeString()->not->toBe('');
    }
});
