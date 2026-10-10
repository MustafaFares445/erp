<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\InventoryOperation;
use App\Models\Shipment;
use App\Models\Warehouse;

/** Read-only, validated waypoint data for operational maps. */
final class OperationalRouteData
{
    /** @return array<string, mixed> */
    public static function shipment(Shipment $shipment): array
    {
        $order = $shipment->order;
        $delivery = $shipment->delivery;
        $source = self::warehouse($shipment->warehouse ?? $delivery?->sourceWarehouse);
        $destination = self::snapshot(
            $delivery?->destination_address_snapshot ?: $order?->destination_address_snapshot,
            __('Customer delivery location'),
        );

        if ($destination === null && $order?->deliveryAddress !== null) {
            $address = $order->deliveryAddress;
            $destination = self::coordinate($address->latitude, $address->longitude, $address->label);
        }
        if ($destination === null && $order?->customer !== null) {
            $destination = self::coordinate($order->customer->latitude, $order->customer->longitude, $order->customer->company_name);
        }

        return self::payload($source, $destination);
    }

    /** @return array<string, mixed> */
    public static function transfer(InventoryOperation $operation): array
    {
        return self::payload(self::warehouse($operation->sourceWarehouse), self::warehouse($operation->destinationWarehouse));
    }

    /** @return array<string, mixed> */
    public static function visit(\App\Models\CustomerVisit $visit): array
    {
        $first = $visit->gpsLogs->sortBy('recorded_at')->first();
        $source = $first !== null
            ? self::coordinate($first->latitude, $first->longitude, __('First recorded GPS location'))
            : null;
        $customer = $visit->customer;
        $destination = $customer !== null
            ? self::coordinate($customer->latitude, $customer->longitude, $customer->company_name)
            : null;
        return self::payload($source, $destination);
    }

    /** @return array<string, mixed> */
    public static function order(\App\Models\Order $order): array
    {
        $destination = self::snapshot($order->destination_address_snapshot, __('Customer delivery location'));
        if ($destination === null && $order->deliveryAddress !== null) {
            $address = $order->deliveryAddress;
            $destination = self::coordinate($address->latitude, $address->longitude, $address->label);
        }
        // A multi-shipment order has separate origins: avoid claiming it has one route.
        $source = null;
        if ($order->shipments()->count() === 1) {
            $shipment = $order->shipments()->with('warehouse')->first();
            $source = self::warehouse($shipment?->warehouse);
        }

        return self::payload($source, $destination);
    }

    /** @param array{latitude: float, longitude: float, label: string}|null $source
     *  @param array{latitude: float, longitude: float, label: string}|null $destination
     *  @return array<string, mixed>
     */
    private static function payload(?array $source, ?array $destination): array
    {
        return ['source' => $source, 'destination' => $destination, 'routingServiceUrl' => config('services.osrm.url')];
    }

    /** @return array{latitude:float,longitude:float,label:string}|null */
    private static function warehouse(?Warehouse $warehouse): ?array
    {
        return $warehouse === null ? null : self::coordinate($warehouse->latitude, $warehouse->longitude, $warehouse->name);
    }

    /** @param array<array-key, mixed>|null $snapshot
     * @return array{latitude:float,longitude:float,label:string}|null
     */
    private static function snapshot(?array $snapshot, string $label): ?array
    {
        return $snapshot === null ? null : self::coordinate(
            $snapshot['latitude'] ?? null,
            $snapshot['longitude'] ?? null,
            is_string($snapshot['address'] ?? null) ? $snapshot['address'] : $label,
        );
    }

    /** @return array{latitude:float,longitude:float,label:string}|null */
    private static function coordinate(mixed $latitude, mixed $longitude, ?string $label): ?array
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) return null;
        $lat = (float) $latitude;
        $lon = (float) $longitude;
        if (! is_finite($lat) || ! is_finite($lon) || abs($lat) > 90 || abs($lon) > 180) return null;
        return ['latitude' => $lat, 'longitude' => $lon, 'label' => (string) ($label ?: __('Location'))];
    }
}
