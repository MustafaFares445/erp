<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerProfile;
use App\Models\PaymentMethod;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Refund> */
final class RefundFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'refund_number' => fake()->unique()->bothify('REF-#######'),
            'customer_id' => CustomerProfile::factory(),
            'payment_method_id' => PaymentMethod::factory(),
            'refund_date' => today(),
            'amount' => fake()->randomFloat(2, 10, 1_000),
            'reason' => fake()->sentence(),
            'status' => 'draft',
        ];
    }
}
