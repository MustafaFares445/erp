<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanTaskStatus;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanTask>
 */
final class PlanTaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_plan_id' => SalesPlan::factory(),
            'customer_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'starts_at' => now()->startOfMonth()->toDateString(),
            'due_at' => now()->endOfMonth()->toDateString(),
            'completed_at' => null,
            'status' => PlanTaskStatus::Pending,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PlanTaskStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function completedWithTimestamp(Carbon $completedAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PlanTaskStatus::Completed,
            'completed_at' => $completedAt,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => now()->subDays(10)->toDateString(),
            'due_at' => now()->subDay()->toDateString(),
            'status' => PlanTaskStatus::Pending,
        ]);
    }
}
