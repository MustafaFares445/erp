<?php

declare(strict_types=1);

namespace App\Services\Orders;

final class DistanceCalculator
{
    public function kilometers(
        float $fromLatitude,
        float $fromLongitude,
        float $toLatitude,
        float $toLongitude,
    ): float {
        $earthRadiusKm = 6371.0088;
        $latitudeDifference = deg2rad($toLatitude - $fromLatitude);
        $longitudeDifference = deg2rad($toLongitude - $fromLongitude);
        $latitudeStart = deg2rad($fromLatitude);
        $latitudeEnd = deg2rad($toLatitude);
        $haversine = sin($latitudeDifference / 2) ** 2
            + cos($latitudeStart) * cos($latitudeEnd) * sin($longitudeDifference / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($haversine), sqrt(1 - $haversine));
    }
}
