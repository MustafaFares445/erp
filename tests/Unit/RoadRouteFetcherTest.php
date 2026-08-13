<?php

declare(strict_types=1);

use App\Services\Orders\RoadRouteFetcher;
use Illuminate\Support\Facades\Http;

it('returns null when the route service is not configured', function (): void {
    config(['services.osrm.url' => null]);

    expect(app(RoadRouteFetcher::class)->fetch(25.2, 55.2, 24.4, 54.3))->toBeNull();
});

it('returns route geometry while ignoring malformed coordinate pairs', function (): void {
    config(['services.osrm.url' => 'https://routing.test/']);

    Http::fake(fn (): mixed => Http::response([
        'routes' => [['geometry' => ['coordinates' => [null, ['bad', 1], [55.2, 25.2]]]]],
    ]));

    $points = app(RoadRouteFetcher::class)->fetch(25.2, 55.2, 24.4, 54.3);

    expect($points)->toBe([[55.2, 25.2]]);
});

it('returns null when the route request fails or returns no usable geometry', function (array $response, int $status): void {
    config(['services.osrm.url' => 'https://routing.test']);
    Http::fake(fn (): mixed => Http::response($response, $status));

    expect(app(RoadRouteFetcher::class)->fetch(25.2, 55.2, 24.4, 54.3))->toBeNull();
})->with([
    'http failure' => [[], 503],
    'missing coordinates' => [['routes' => [['geometry' => []]]], 200],
    'only malformed pairs' => [['routes' => [['geometry' => ['coordinates' => [['bad', 1], [55.2]]]]]], 200],
]);

it('returns null when the route transport throws', function (): void {
    config(['services.osrm.url' => 'https://routing.test']);
    Http::fake(fn () => throw new RuntimeException('routing service unavailable'));

    expect(app(RoadRouteFetcher::class)->fetch(25.2, 55.2, 24.4, 54.3))->toBeNull();
});
