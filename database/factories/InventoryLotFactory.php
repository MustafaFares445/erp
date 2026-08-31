<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLot>
 */
final class InventoryLotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'inventory_receipt_item_id' => null,
            'lot_number' => fake()->bothify('LOT-######'),
            'expires_at' => fake()->dateTimeBetween('+1 day', '+1 year'),
            // Deprecated compatibility columns. Runtime inventory logic never reads them.
            'on_hand_quantity' => fake()->randomFloat(3, 1, 100),
            'reserved_quantity' => 0,
        ];
    }

    #[\Override]
    public function configure(): static
    {
        return $this->afterCreating(function (InventoryLot $lot): void {
            if ($lot->warehouse_id === null || $lot->conditionBalances()->exists()) {
                return;
            }

            $warehouseId = (int) $lot->warehouse_id;
            $onHand = (string) $lot->on_hand_quantity;
            $reserved = (string) $lot->reserved_quantity;

            InventoryLotBalance::query()->forceCreate([
                'inventory_lot_id' => $lot->getKey(),
                'warehouse_id' => $warehouseId,
                'stock_condition' => StockCondition::Saleable,
                'on_hand_base_quantity' => $onHand,
                'reserved_base_quantity' => $reserved,
            ]);

            foreach ([StockCondition::Quarantine, StockCondition::Damaged] as $condition) {
                InventoryLotBalance::query()->forceCreate([
                    'inventory_lot_id' => $lot->getKey(),
                    'warehouse_id' => $warehouseId,
                    'stock_condition' => $condition,
                    'on_hand_base_quantity' => '0.000000',
                    'reserved_base_quantity' => '0.000000',
                ]);
            }

            // Factories may accept a warehouse/quantity state as a convenient way to seed
            // the canonical balance grain, but the persisted lot identity itself remains
            // warehouse- and quantity-free just like production-created lots.
            $lot->forceFill([
                'warehouse_id' => null,
                'on_hand_quantity' => '0.000000',
                'reserved_quantity' => '0.000000',
            ])->saveQuietly();
        });
    }

    public function canonical(): static
    {
        return $this->state(fn (): array => [
            'warehouse_id' => null,
            'on_hand_quantity' => 0,
            'reserved_quantity' => 0,
        ]);
    }
}
