<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryLot;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLot>
 */
final class InventoryLotFactory extends Factory
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
            'inventory_receipt_item_id' => null,
            'lot_number' => fake()->bothify('LOT-######'),
            'expires_at' => fake()->dateTimeBetween('+1 day', '+1 year'),
            'on_hand_quantity' => fake()->randomFloat(3, 1, 100),
            'reserved_quantity' => 0,
        ];
    }
}
