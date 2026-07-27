<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\ProductVariant;
use App\Models\StockReservation;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockReservation>
 */
class StockReservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'quantity' => fake()->randomFloat(3, 1, 20),
            'source_type' => 'manual',
            'source_id' => fake()->numberBetween(1, 1000),
            'expires_at' => null,
            'status' => ReservationStatus::Active,
        ];
    }
}
