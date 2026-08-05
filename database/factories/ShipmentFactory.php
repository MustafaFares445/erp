<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShipmentStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Shipment> */
final class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'warehouse_id' => Warehouse::factory(),
            'tracking_number' => 'TRK-'.fake()->unique()->numerify('########'),
            'status' => ShipmentStatus::InTransit,
        ];
    }

    public function arrived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ShipmentStatus::Arrived,
            'confirmed_by_type' => 'system',
            'confirmed_at' => now(),
        ]);
    }

    public function forCustomer(CustomerProfile $customer): static
    {
        return $this->for(Order::factory()->for($customer, 'customer'));
    }
}
