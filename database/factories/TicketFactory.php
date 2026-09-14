<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TicketEquipmentSource;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ticket> */
final class TicketFactory extends Factory
{
    /** @return array<string, mixed> */
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

    public function triagedForMaintenance(): static
    {
        return $this->state(fn (array $attributes): array => [
            'equipment_source' => TicketEquipmentSource::External,
            'external_equipment_name' => 'External equipment',
            'warranty_status' => WarrantyStatus::NotApplicable,
            'service_path' => TicketServicePath::Maintenance,
            'triaged_at' => now(),
            'is_chargeable' => false,
            'status' => TicketStatus::Live,
        ]);
    }

    public function withPriority(TicketPriority $priority): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => $priority,
        ]);
    }
}
