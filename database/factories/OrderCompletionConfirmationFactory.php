<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\OrderCompletionConfirmation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderCompletionConfirmation> */
final class OrderCompletionConfirmationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'customer_id' => CustomerProfile::factory(),
            'confirmed_at' => now(),
            'source_channel' => 'customer_app',
        ];
    }
}
