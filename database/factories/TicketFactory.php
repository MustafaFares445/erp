<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
final class TicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_number' => 'TCK-'.mb_str_pad((string) fake()->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'customer_id' => CustomerProfile::factory(),
            'assigned_employee_id' => null,
            'type' => TicketType::GeneralSupport,
            'priority' => TicketPriority::Normal,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => TicketStatus::Pending,
            'pending_reason' => null,
            'is_chargeable' => false,
        ];
    }

    public function chargeable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_chargeable' => true,
            'status' => TicketStatus::PendingPayment,
            'pending_reason' => 'Payment is awaited before this ticket can be worked.',
        ]);
    }

    public function withPriority(TicketPriority $priority): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => $priority,
        ]);
    }
}
