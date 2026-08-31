<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryReservation;
use App\Models\InventoryReservationAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryReservationAllocation> */
final class InventoryReservationAllocationFactory extends Factory
{
    protected $model = InventoryReservationAllocation::class;

    public function definition(): array
    {
        return [
            'inventory_reservation_id' => InventoryReservation::factory(),
            'inventory_lot_id' => null,
            'serialized_inventory_unit_id' => null,
            'base_quantity' => fake()->randomFloat(3, 1, 20),
        ];
    }
}
