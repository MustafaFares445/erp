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

it('recognizes points outside the UAE', function (float $latitude, float $longitude): void {
    expect(app(UaeBoundary::class)->contains($latitude, $longitude))->toBeFalse();
})->with([
    'Damascus, Syria' => [33.5138, 36.2765],
    'Muscat, Oman' => [23.5880, 58.3829],
    'open ocean' => [20.0, 60.0],
]);
