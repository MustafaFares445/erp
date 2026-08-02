<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PriceFloorOverride;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceFloorOverride>
 */
class PriceFloorOverrideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $minPrice = fake()->randomFloat(2, 20, 100);

        return [
            'product_variant_id' => ProductVariant::factory(),
            'customer_user_id' => null,
            'pricing_tier_id' => null,
            'attempted_price' => $minPrice - fake()->randomFloat(2, 1, 10),
            'min_price' => $minPrice,
            'approved_by' => User::factory(),
            'approved_at' => now(),
            'reason' => fake()->sentence(),
        ];
    }
}
