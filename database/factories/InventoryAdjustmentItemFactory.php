<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StockCondition;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryAdjustmentItem>
 */
final class InventoryAdjustmentItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $oldQuantity = fake()->randomFloat(3, 0, 50);
        $newQuantity = fake()->randomFloat(3, 0, 50);

        return [
            'inventory_adjustment_id' => InventoryAdjustment::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'stock_condition' => StockCondition::Saleable,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'difference' => $newQuantity - $oldQuantity,
        ];
    }
}
