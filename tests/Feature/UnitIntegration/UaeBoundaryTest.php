<?php

declare(strict_types=1);

use App\Services\Orders\UaeBoundary;

it('recognizes points inside the UAE mainland', function (float $latitude, float $longitude): void {
    expect(app(UaeBoundary::class)->contains($latitude, $longitude))->toBeTrue();
})->with([
    'Dubai' => [25.2048, 55.2708],
    'Abu Dhabi' => [24.4539, 54.3773],
    'Al Ain, near the Oman border' => [24.2075, 55.7447],
]);

it('rejects missing and malformed boundary files', function (): void {
    set_error_handler(static fn (): bool => true);

    try {
        expect(fn (): UaeBoundary => new UaeBoundary('missing-boundary.json'))
            ->toThrow(RuntimeException::class);
    } finally {
        restore_error_handler();
    }

    $path = tempnam(sys_get_temp_dir(), 'uae-boundary-');

    if ($path === false) {
        throw new RuntimeException('Could not create a temporary boundary file.');
    }

    file_put_contents($path, '{"coordinates":"invalid"}');

    try {
        expect(fn (): UaeBoundary => new UaeBoundary($path))
            ->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
});

it('ignores malformed rings while loading a boundary file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'uae-boundary-');

    if ($path === false) {
        throw new RuntimeException('Could not create a temporary boundary file.');
    }

    file_put_contents($path, '{"coordinates":[null,[],[null]]}');

    try {
        expect(new UaeBoundary($path)->contains(25.2, 55.2))->toBeFalse();
    } finally {
        unlink($path);
    }
});

it('recognizes points outside the UAE', function (float $latitude, float $longitude): void {
    expect(app(UaeBoundary::class)->contains($latitude, $longitude))->toBeFalse();
})->with([
    'Damascus, Syria' => [33.5138, 36.2765],
    'Muscat, Oman' => [23.5880, 58.3829],
    'open ocean' => [20.0, 60.0],
]);
