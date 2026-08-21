<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaintenanceStatus;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\MaintenanceRecord;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceRecord>
 */
final class MaintenanceRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => CustomerProfile::factory(),
            'ticket_id' => null,
            'description' => fake()->paragraph(),
            'warranty_status' => WarrantyStatus::Unknown,
            'status' => MaintenanceStatus::Open,
        ];
    }

    public function fromTicket(): static
    {
        return $this->state(function (array $attributes): array {
            $ticket = Ticket::factory()->create();

            return [
                'ticket_id' => $ticket->getKey(),
                'customer_id' => $ticket->customer_id,
            ];
        });
    }

    public function standalone(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ticket_id' => null,
        ]);
    }

    public function covered(): static
    {
        return $this->state(fn (array $attributes): array => [
            'warranty_status' => WarrantyStatus::Covered,
            'warranty_expiry_date' => now()->addYear(),
        ]);
    }
}
