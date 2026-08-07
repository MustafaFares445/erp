<?php

declare(strict_types=1);

use App\Enums\PlanTaskStatus;
use App\Models\SalesPlan;
use App\Models\TaskStatusLog;
use App\Services\Employees\PlanTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets completed_at on entering Completed and clears it on reopen', function (): void {
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);
    $task = app(PlanTaskService::class)->create($plan, [
        'title' => 'Deliver samples',
        'starts_at' => '2026-03-01',
        'due_at' => '2026-03-10',
    ]);

    $service = app(PlanTaskService::class);
    $completed = $service->transition($task, PlanTaskStatus::Completed);
    expect($completed->completed_at)->not->toBeNull();

    $reopened = $service->transition($completed, PlanTaskStatus::InProgress);
    expect($reopened->completed_at)->toBeNull();
});

it('always agrees with the latest Completed log entry for completed_at', function (): void {
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);
    $task = app(PlanTaskService::class)->create($plan, [
        'title' => 'Deliver samples',
        'starts_at' => '2026-03-01',
        'due_at' => '2026-03-10',
    ]);
    $service = app(PlanTaskService::class);

    $service->transition($task, PlanTaskStatus::Completed);
    $service->transition($task->fresh(), PlanTaskStatus::InProgress);

    $recompleted = $service->transition($task->fresh(), PlanTaskStatus::Completed);

    $latestCompletedLog = TaskStatusLog::query()
        ->where('plan_task_id', $task->id)
        ->where('to_status', PlanTaskStatus::Completed->value)
        ->orderByDesc('id')
        ->first();

    expect($recompleted->fresh()->completed_at->toDateTimeString())
        ->toBe($latestCompletedLog->created_at->toDateTimeString());
});
