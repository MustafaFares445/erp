<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerProfile;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'SO-'.mb_str_pad((string) fake()->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'customer_id' => CustomerProfile::factory(),
            'status' => 'ready',
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
