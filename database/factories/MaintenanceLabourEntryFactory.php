<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MaintenanceLabourEntry;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceLabourEntry>
 */
final class MaintenanceLabourEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $minutes = fake()->numberBetween(15, 240);
        $hourlyRateMinor = fake()->numberBetween(2000, 10000);

        return [
            'maintenance_record_id' => MaintenanceRecord::factory(),
            'service_record_id' => null,
            'employee_id' => User::factory(),
            'performed_on' => now()->toDateString(),
            'minutes' => $minutes,
            'hourly_rate_minor' => $hourlyRateMinor,
            'total_cost_minor' => (int) round($minutes / 60 * $hourlyRateMinor),
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
