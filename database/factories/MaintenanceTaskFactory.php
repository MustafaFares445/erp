<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceTask>
 */
final class MaintenanceTaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'maintenance_record_id' => MaintenanceRecord::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'due_at' => now()->addDays(7),
            'status' => MaintenanceStatus::Open,
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'due_at' => now()->subDays(2),
            'status' => MaintenanceStatus::InProgress,
        ]);
    }

    public function dueSoon(): static
    {
        return $this->state(fn (array $attributes): array => [
            'due_at' => now()->addHours(12),
            'status' => MaintenanceStatus::Open,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MaintenanceStatus::Closed,
        ]);
    }
}
