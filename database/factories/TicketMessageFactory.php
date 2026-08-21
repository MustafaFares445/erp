<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketMessage>
 */
final class TicketMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'sender_user_id' => User::factory(),
            'message' => fake()->paragraph(),
            'is_internal_note' => false,
        ];
    }

    public function internalNote(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_internal_note' => true,
        ]);
    }

    public function customerVisible(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_internal_note' => false,
        ]);
    }
}
