<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerPricingTier;
use App\Models\PricingTier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerPricingTier> */
final class CustomerPricingTierFactory extends Factory
{
    public function definition(): array
    {
        return ['customer_user_id' => User::factory(), 'pricing_tier_id' => PricingTier::factory(), 'is_active' => true];
    }
}
