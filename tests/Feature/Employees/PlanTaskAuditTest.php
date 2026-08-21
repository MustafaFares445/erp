<?php

declare(strict_types=1);

use App\Enums\PlanTaskStatus;
use App\Models\AuditLog;
use App\Models\SalesPlan;
use App\Models\TaskStatusLog;
use App\Services\Employees\PlanTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes an AuditLogger entry for every task status transition, distinct from the TaskStatusLog record', function (): void {
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);
    $task = app(PlanTaskService::class)->create($plan, [
        'title' => 'Deliver samples',
        'starts_at' => '2026-03-01',
        'due_at' => '2026-03-10',
    ]);

    app(PlanTaskService::class)->transition($task, PlanTaskStatus::Completed);
    app(PlanTaskService::class)->transition($task->fresh(), PlanTaskStatus::InProgress);

    $auditEntries = AuditLog::query()->where('description', 'task.transitioned')->where('subject_id', $task->id)->get();
    $statusLogEntries = TaskStatusLog::query()->where('plan_task_id', $task->id)->get();

    expect($auditEntries)->toHaveCount(2)
        ->and($statusLogEntries)->toHaveCount(3);
});
