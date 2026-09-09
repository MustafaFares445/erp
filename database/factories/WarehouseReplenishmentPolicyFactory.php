<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WarehouseReplenishmentPolicy>
 */
final class WarehouseReplenishmentPolicyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'min_quantity' => fake()->randomFloat(3, 5, 15),
            'max_quantity' => fake()->randomFloat(3, 50, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
