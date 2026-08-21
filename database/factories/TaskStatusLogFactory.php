<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanTaskStatus;
use App\Models\PlanTask;
use App\Models\TaskStatusLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskStatusLog>
 */
final class TaskStatusLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_task_id' => PlanTask::factory(),
            'from_status' => null,
            'to_status' => PlanTaskStatus::Pending,
            'note' => null,
            'actor_id' => User::factory()->admin(),
            'created_at' => now(),
        ];
    }
}
