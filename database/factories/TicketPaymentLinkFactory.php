<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentLinkStatus;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketPaymentLink>
 */
final class TicketPaymentLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory()->chargeable(),
            'amount' => fake()->randomFloat(2, 10, 500),
            'currency' => 'USD',
            'status' => PaymentLinkStatus::Pending,
        ];
    }

    public function settled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentLinkStatus::Settled,
            'settled_by' => User::factory()->admin(),
            'settled_at' => now(),
            'payment_method_reference' => fake()->uuid(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentLinkStatus::Cancelled,
        ]);
    }
}
