<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryStock> */
final class InventoryStockFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $onHand = fake()->randomFloat(3, 20, 200);
        $reserved = fake()->randomFloat(3, 0, 5);

        return [
            'product_variant_id' => ProductVariant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'on_hand_quantity' => $onHand,
            'reserved_quantity' => $reserved,
            'damaged_quantity' => 0,
            'available_quantity' => $onHand - $reserved,
        ];
    }

    /**
     * Quantity state suitable for a low-stock scenario once a matching
     * WarehouseReplenishmentPolicy is configured by the test or seeder.
     */
    public function lowStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'on_hand_quantity' => 10,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'available_quantity' => 10,
        ]);
    }
}
