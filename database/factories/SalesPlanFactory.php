<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SalesPlanStatus;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesPlan>
 */
final class SalesPlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => EmployeeProfile::factory(),
            'name' => fake()->words(3, true),
            'month' => now()->startOfMonth()->toDateString(),
            'active_month' => null,
            'task_weight' => 40,
            'visit_weight' => 30,
            'schedule_weight' => 20,
            'work_time_weight' => 10,
            'required_visit_minutes' => null,
            'status' => SalesPlanStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => SalesPlanStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SalesPlanStatus::InProgress,
            'active_month' => $attributes['month'] ?? now()->startOfMonth()->toDateString(),
            'published_at' => now(),
        ]);
    }

    public function active(): static
    {
        return $this->inProgress();
    }

    public function withTasks(int $count = 3): static
    {
        return $this->afterCreating(function (SalesPlan $plan) use ($count): void {
            PlanTask::factory()->count($count)->for($plan, 'salesPlan')->create();
        });
    }
}
