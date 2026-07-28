<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PriceHistory;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceHistory>
 */
class PriceHistoryFactory extends Factory
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
            'cost_price' => fake()->randomFloat(2, 1, 50),
            'base_price' => fake()->randomFloat(2, 50, 100),
            'min_price' => fake()->randomFloat(2, 1, 49),
            'markup_percent' => fake()->randomFloat(2, 5, 50),
            'changed_by' => User::factory(),
        ];
    }
}
