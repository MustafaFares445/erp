<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentProvider;
use App\Enums\PaymentTransactionStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentTransaction>
 */
final class PaymentTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => CustomerProfile::factory(),
            'payment_id' => null,
            'provider' => PaymentProvider::Stripe,
            'purpose_type' => Order::class,
            'purpose_id' => Order::factory(),
            'checkout_session_id' => 'cs_test_'.fake()->unique()->uuid(),
            'payment_intent_id' => 'pi_test_'.fake()->unique()->uuid(),
            'amount_minor' => fake()->numberBetween(1000, 500000),
            'currency' => 'AED',
            'status' => PaymentTransactionStatus::Pending,
            'idempotency_key' => (string) fake()->unique()->uuid(),
            'metadata' => [],
        ];
    }

    public function succeeded(): self
    {
        return $this->state(fn (): array => [
            'status' => PaymentTransactionStatus::Succeeded,
            'succeeded_at' => now(),
        ]);
    }
}
