<?php

declare(strict_types=1);

use App\Enums\DeliveryType;
use App\Services\Orders\DeliveryTypeResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

it('classifies a route that stays inside the UAE as inner', function (): void {
    Http::fake(['*router.project-osrm.org*' => Http::response([
        'code' => 'Ok',
        'routes' => [['geometry' => [
            'type' => 'LineString',
            'coordinates' => [[55.2700, 25.2100], [55.2708, 25.2048]],
        ]]],
    ])]);

    $deliveryType = app(DeliveryTypeResolver::class)->resolve(25.2100, 55.2700, 25.2048, 55.2708);

    expect($deliveryType)->toBe(DeliveryType::Inner);
});

it('classifies a route that dips outside the UAE as outer', function (): void {
    Http::fake(['*router.project-osrm.org*' => Http::response([
        'code' => 'Ok',
        'routes' => [['geometry' => [
            'type' => 'LineString',
            'coordinates' => [[55.27, 25.21], [58.3829, 23.5880], [55.2708, 25.2048]],
        ]]],
    ])]);

    $deliveryType = app(DeliveryTypeResolver::class)->resolve(25.2100, 55.2700, 25.2048, 55.2708);

    expect($deliveryType)->toBe(DeliveryType::Outer);
});

it('falls back to a straight line between the two points when routing fails', function (): void {
    Http::fake(fn (): Response => Http::response('', 500));

    expect(app(DeliveryTypeResolver::class)->resolve(25.2100, 55.2700, 25.2048, 55.2708))->toBe(DeliveryType::Inner)
        ->and(app(DeliveryTypeResolver::class)->resolve(25.2100, 55.2700, 23.5880, 58.3829))->toBe(DeliveryType::Outer);
});
