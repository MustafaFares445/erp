<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StockCondition;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryCountLine>
 */
final class InventoryCountLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_count_id' => InventoryCount::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'stock_condition' => StockCondition::Saleable,
            'system_base_quantity' => fake()->randomFloat(3, 0, 50),
            'counted_base_quantity' => null,
        ];
    }
}
