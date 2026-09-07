<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OccurrenceStatus;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceScheduleOccurrence>
 */
final class MaintenanceScheduleOccurrenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'maintenance_schedule_id' => MaintenanceSchedule::factory(),
            'due_on' => now()->addMonth()->startOfDay(),
            'status' => OccurrenceStatus::Pending,
        ];
    }

    public function pastDue(): static
    {
        return $this->state(fn (array $attributes): array => ['due_on' => now()->subDays(3)->startOfDay()]);
    }
}
