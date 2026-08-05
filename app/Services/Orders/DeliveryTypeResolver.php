<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\DeliveryType;

/**
 * Classifies a shipment as {@see DeliveryType::Inner} or {@see DeliveryType::Outer} by checking
 * whether the driving route between two points ever leaves the UAE — e.g. roads to Hatta or the
 * Musandam exclaves that briefly cross into Oman. Falls back to a straight line between the two
 * points when the routing service is unavailable.
 */
final readonly class DeliveryTypeResolver
{
    public function __construct(
        private RoadRouteFetcher $routeFetcher,
        private UaeBoundary $boundary,
    ) {}

    public function resolve(
        float $originLatitude,
        float $originLongitude,
        float $destinationLatitude,
        float $destinationLongitude,
    ): DeliveryType {
        $points = $this->routeFetcher->fetch($originLatitude, $originLongitude, $destinationLatitude, $destinationLongitude)
            ?? [[$originLongitude, $originLatitude], [$destinationLongitude, $destinationLatitude]];

        foreach ($points as [$longitude, $latitude]) {
            if (! $this->boundary->contains($latitude, $longitude)) {
                return DeliveryType::Outer;
            }
        }

        return DeliveryType::Inner;
    }
}
