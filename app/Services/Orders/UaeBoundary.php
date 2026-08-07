<?php

declare(strict_types=1);

namespace App\Services\Orders;

use RuntimeException;

/**
 * Point-in-polygon test against the United Arab Emirates' national boundary, including its
 * enclaves (e.g. the Omani Madha enclave, held as a hole, and the UAE's Nahwa enclave within it).
 *
 * @phpstan-type Ring list<array{0: float, 1: float}>
 * @phpstan-type Polygon array{exterior: Ring, holes: list<Ring>}
 */
final readonly class UaeBoundary
{
    /** @var list<Polygon> */
    private array $polygons;

    public function __construct(?string $path = null)
    {
        $path ??= resource_path('data/uae-boundary.json');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('The UAE boundary file could not be read: '.$path);
        }

        $geometry = json_decode($contents, true);

        if (! is_array($geometry) || ! is_array($geometry['coordinates'] ?? null)) {
            throw new RuntimeException('The UAE boundary file is not valid GeoJSON: '.$path);
        }

        $polygons = [];

        foreach ($geometry['coordinates'] as $rings) {
            if (! is_array($rings)) {
                continue;
            }

            if ($rings === []) {
                continue;
            }

            $parsedRings = array_values(array_map($this->parseRing(...), $rings));

            $polygons[] = [
                'exterior' => $parsedRings[0],
                'holes' => array_slice($parsedRings, 1),
            ];
        }

        $this->polygons = $polygons;
    }

    /** @return Ring */
    private function parseRing(mixed $ring): array
    {
        if (! is_array($ring)) {
            return [];
        }

        $points = [];

        foreach ($ring as $point) {
            if (is_array($point) && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null)) {
                $points[] = [(float) $point[0], (float) $point[1]];
            }
        }

        return $points;
    }

    public function contains(float $latitude, float $longitude): bool
    {
        foreach ($this->polygons as $polygon) {
            if (! $this->ringContains($polygon['exterior'], $latitude, $longitude)) {
                continue;
            }

            $inHole = array_any($polygon['holes'], fn (array $hole): bool => $this->ringContains($hole, $latitude, $longitude));

            if (! $inHole) {
                return true;
            }
        }

        return false;
    }

    /**
     * Standard ray-casting (PNPOLY) test. Ring points are GeoJSON [longitude, latitude] pairs.
     *
     * @param  Ring  $ring
     */
    private function ringContains(array $ring, float $latitude, float $longitude): bool
    {
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $longitudeI = $ring[$i][0];
            $latitudeI = $ring[$i][1];
            $longitudeJ = $ring[$j][0];
            $latitudeJ = $ring[$j][1];

            $crossesLatitude = ($latitudeI > $latitude) !== ($latitudeJ > $latitude);
            $isLeftOfEdge = $crossesLatitude
                && $longitude < ($longitudeJ - $longitudeI) * ($latitude - $latitudeI) / ($latitudeJ - $latitudeI) + $longitudeI;

            if ($isLeftOfEdge) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
