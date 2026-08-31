<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\InventoryReservation;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryReservation> */
final class InventoryReservationFactory extends Factory
{
    protected $model = InventoryReservation::class;

    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'source_type' => 'manual',
            'source_id' => fake()->numberBetween(1, 1000),
            'source_line_type' => null,
            'source_line_id' => null,
            'base_quantity' => fake()->randomFloat(3, 1, 20),
            'status' => ReservationStatus::Active,
            'expires_at' => null,
        ];
    }
}
