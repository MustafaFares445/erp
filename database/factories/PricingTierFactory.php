<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PricingTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PricingTier> */
final class PricingTierFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->word(), 'discount_percent' => 10, 'customer_user_id' => null, 'is_active' => true];
    }
}
