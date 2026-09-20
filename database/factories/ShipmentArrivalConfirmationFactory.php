<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShipmentConfirmationSource;
use App\Models\Shipment;
use App\Models\ShipmentArrivalConfirmation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShipmentArrivalConfirmation>
 */
final class ShipmentArrivalConfirmationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'confirmed_by_type' => ShipmentConfirmationSource::Customer,
            'confirmed_at' => now(),
            'source_channel' => 'dashboard',
        ];
    }
}
