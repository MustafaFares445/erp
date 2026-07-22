<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryStock>
 */
final class InventoryStockFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $onHand = fake()->randomFloat(3, 20, 200);
        $reserved = fake()->randomFloat(3, 0, 5);

        return [
            'product_variant_id' => ProductVariant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'on_hand_quantity' => $onHand,
            'reserved_quantity' => $reserved,
            'available_quantity' => $onHand - $reserved,
            'reorder_level' => fake()->randomFloat(3, 5, 15),
        ];
    }

    /**
     * Available quantity at or below the reorder level (low-stock).
     */
    public function lowStock(): static
    {
        return $this->state(function (array $attributes): array {
            $reorderLevel = 10.0;

            return [
                'on_hand_quantity' => $reorderLevel,
                'reserved_quantity' => 0,
                'available_quantity' => $reorderLevel,
                'reorder_level' => $reorderLevel,
            ];
        });
    }

    /**
     * No reorder threshold configured — never flagged as low-stock.
     */
    public function withoutReorderLevel(): static
    {
        return $this->state(fn (array $attributes): array => [
            'reorder_level' => null,
        ]);
    }
}
