<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
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
            'damaged_quantity' => 0,
            'available_quantity' => $onHand - $reserved,
        ];
    }

    /**
     * Available quantity at or below an active replenishment policy's
     * minimum (low-stock). No policy is created unless this state is used —
     * a stock row with no matching {@see WarehouseReplenishmentPolicy} is
     * never low-stock, the same way it was never low-stock with a null
     * `reorder_level` before that column existed.
     */
    public function lowStock(): static
    {
        return $this->state(function (array $attributes): array {
            $minQuantity = 10.0;

            return [
                'on_hand_quantity' => $minQuantity,
                'reserved_quantity' => 0,
                'available_quantity' => $minQuantity,
            ];
        })->afterCreating(function (InventoryStock $stock): void {
            WarehouseReplenishmentPolicy::query()->updateOrCreate(
                ['warehouse_id' => $stock->warehouse_id, 'product_variant_id' => $stock->product_variant_id],
                ['min_quantity' => 10.0, 'max_quantity' => 100.0, 'is_active' => true],
            );
        });
    }
}
