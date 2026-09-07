<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MaintenanceRecord;
use App\Models\MaintenanceThirdPartyCost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceThirdPartyCost>
 */
final class MaintenanceThirdPartyCostFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'maintenance_record_id' => MaintenanceRecord::factory(),
            'supplier_id' => null,
            'bill_id' => null,
            'description' => fake()->sentence(4),
            'amount_minor' => fake()->numberBetween(1000, 50000),
            'incurred_on' => now()->toDateString(),
            'created_by' => User::factory(),
        ];
    }
}
