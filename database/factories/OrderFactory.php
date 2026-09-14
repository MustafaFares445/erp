<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
final class OrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_number' => 'SO-'.mb_str_pad((string) fake()->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'customer_id' => CustomerProfile::factory(),
            'status' => OrderStatus::Released->value,
            'confirmed_at' => now(),
            'released_at' => now(),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function draft(): self
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Draft->value,
            'confirmed_at' => null,
            'released_at' => null,
        ]);
    }

    public function confirmed(): self
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now(),
            'released_at' => null,
        ]);
    }
}
