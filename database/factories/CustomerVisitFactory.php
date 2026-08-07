<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VisitRecordChannel;
use App\Enums\VisitStatus;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerVisit>
 */
final class CustomerVisitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => EmployeeProfile::factory(),
            'plan_task_id' => PlanTask::factory(),
            'customer_id' => null,
            'recorded_channel' => VisitRecordChannel::Dashboard,
            'planned_at' => now(),
            'checked_in_at' => null,
            'checked_out_at' => null,
            'outcome' => null,
            'review_note' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'status' => VisitStatus::Planned,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => VisitStatus::Completed,
            'checked_in_at' => now()->subMinutes(45),
            'checked_out_at' => now(),
        ]);
    }

    public function completedWithoutCheckout(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => VisitStatus::Completed,
            'checked_in_at' => now()->subMinutes(45),
            'checked_out_at' => null,
        ]);
    }

    public function fieldRecorded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'recorded_channel' => VisitRecordChannel::Field,
        ]);
    }

    public function unattributed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'plan_task_id' => null,
        ]);
    }
}
