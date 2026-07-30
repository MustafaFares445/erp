<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductSubscriptionDiscountType;
use App\Enums\ProductSubscriptionVisibility;
use App\Models\ProductSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductSubscription>
 */
final class ProductSubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'discount_type' => ProductSubscriptionDiscountType::Percentage,
            'discount_value' => 10,
            'visibility' => ProductSubscriptionVisibility::Public,
            'is_active' => false,
            'created_by' => User::factory()->admin(),
            'updated_by' => User::factory()->admin(),
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }

    public function restricted(): static
    {
        return $this->state(['visibility' => ProductSubscriptionVisibility::Restricted]);
    }

    public function fixed(): static
    {
        return $this->state(['discount_type' => ProductSubscriptionDiscountType::Fixed]);
    }

    public function scheduled(): static
    {
        return $this->active()->state(['valid_from' => today()->addDay()]);
    }

    public function expired(): static
    {
        return $this->active()->state(['valid_until' => today()->subDay()]);
    }
}
