<?php

declare(strict_types=1);

namespace App\Services\Orders;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches a driving route's geometry from the OSRM routing service configured at
 * `services.osrm.url` — the same service the delivery map draws routes with.
 */
final readonly class RoadRouteFetcher
{
    private const int TimeoutSeconds = 3;

    /**
     * @return list<array{0: float, 1: float}>|null GeoJSON [longitude, latitude] pairs along the
     *                                              route, or null when the route could not be resolved.
     */
    public function fetch(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): ?array
    {
        $configuredUrl = config('services.osrm.url');

        if (! is_string($configuredUrl) || $configuredUrl === '') {
            return null;
        }

        $baseUrl = mb_rtrim($configuredUrl, '/');
        $coordinates = "{$fromLongitude},{$fromLatitude};{$toLongitude},{$toLatitude}";

        try {
            $response = Http::timeout(self::TimeoutSeconds)->get("{$baseUrl}/route/v1/driving/{$coordinates}", [
                'overview' => 'full',
                'geometries' => 'geojson',
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $coordinatePairs = $response->json('routes.0.geometry.coordinates');

        if (! is_array($coordinatePairs)) {
            return null;
        }

        $points = [];

        foreach ($coordinatePairs as $pair) {
            if (! is_array($pair) || ! is_numeric($pair[0] ?? null) || ! is_numeric($pair[1] ?? null)) {
                continue;
            }

            $points[] = [(float) $pair[0], (float) $pair[1]];
        }

        return $points === [] ? null : $points;
    }
}
