<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Models\MaintenanceSchedule;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceSchedule>
 */
final class MaintenanceScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstDueOn = now()->addMonth()->startOfDay();

        return [
            'schedule_number' => 'MSCH-'.mb_str_pad((string) fake()->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'serialized_inventory_unit_id' => SerializedInventoryUnit::factory(),
            'customer_id' => null,
            'name' => 'Quarterly service',
            'interval_type' => MaintenanceIntervalType::Months,
            'interval_value' => 3,
            'lead_time_days' => 7,
            'first_due_on' => $firstDueOn,
            'next_due_on' => $firstDueOn,
            'last_completed_on' => null,
            'is_active' => true,
            'billing_type' => MaintenanceBillingType::Unbilled,
            'checklist' => null,
            'created_by' => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
