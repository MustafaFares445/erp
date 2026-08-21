<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PricingTierDiscountType;
use App\Enums\PricingTierType;
use App\Enums\PricingTierVisibility;
use App\Models\PricingTier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PricingTier> */
final class PricingTierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'tier_type' => PricingTierType::General,
            'discount_type' => PricingTierDiscountType::Percentage,
            'discount_value' => 10,
            'customer_user_id' => null,
            'visibility' => null,
            'valid_from' => null,
            'valid_until' => null,
            'is_active' => true,
        ];
    }

    public function customerSpecific(): self
    {
        return $this->state(fn (): array => [
            'tier_type' => PricingTierType::CustomerSpecific,
            'customer_user_id' => User::factory()->customer(),
        ]);
    }

    public function productScoped(): self
    {
        return $this->state(fn (): array => [
            'tier_type' => PricingTierType::ProductScoped,
            'visibility' => PricingTierVisibility::Public,
            'customer_user_id' => null,
        ]);
    }

    public function fixed(): self
    {
        return $this->productScoped()->state(fn (): array => [
            'discount_type' => PricingTierDiscountType::Fixed,
        ]);
    }

    public function restricted(): self
    {
        return $this->productScoped()->state(fn (): array => [
            'visibility' => PricingTierVisibility::Restricted,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
