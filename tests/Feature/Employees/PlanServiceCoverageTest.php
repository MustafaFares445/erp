<?php

declare(strict_types=1);

use App\Enums\PlanTaskStatus;
use App\Enums\SalesPlanStatus;
use App\Models\AuditLog;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use App\Services\Employees\PlanTaskService;
use App\Services\Employees\SalesPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates a task within the plan window and audits the change', function (): void {
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);
    $task = app(PlanTaskService::class)->create($plan, [
        'title' => 'Original title',
        'starts_at' => '2026-03-05',
        'due_at' => '2026-03-10',
    ]);

    $updated = app(PlanTaskService::class)->update($task, ['title' => 'Revised title']);

    expect($updated->title)->toBe('Revised title')
        ->and(AuditLog::query()->where('action', 'task.updated')->where('entity_id', $task->id)->exists())->toBeTrue();
});

it('throws a LogicException when a task has somehow lost its parent plan', function (): void {
    $task = PlanTask::factory()->create();
    $task->setRelation('salesPlan', null);

    expect(fn () => app(PlanTaskService::class)->update($task, ['title' => 'New title']))
        ->toThrow(LogicException::class);
});

it('rejects a task date of an unsupported type', function (): void {
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);

    expect(fn () => app(PlanTaskService::class)->create($plan, [
        'title' => 'Bad date type',
        'starts_at' => 12345,
        'due_at' => '2026-03-10',
    ]))->toThrow(DomainException::class);
});

it('rejects a task transition the enum does not allow, even called directly', function (): void {
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);
    $task = app(PlanTaskService::class)->create($plan, [
        'title' => 'Task',
        'starts_at' => '2026-03-05',
        'due_at' => '2026-03-10',
    ]);
    app(PlanTaskService::class)->transition($task, PlanTaskStatus::Cancelled);

    expect(fn () => app(PlanTaskService::class)->transition($task->fresh(), PlanTaskStatus::Completed))
        ->toThrow(InvalidStatusTransition::class);
});

it('rejects a plan transition the enum does not allow, even called directly', function (): void {
    $plan = SalesPlan::factory()->create();

    expect(fn () => app(SalesPlanService::class)->transition($plan, SalesPlanStatus::Completed))
        ->toThrow(InvalidStatusTransition::class);
});

it('rejects activation at the service layer when another active plan already exists for that employee and month', function (): void {
    $plan = SalesPlan::factory()->withTasks(1)->create([
        'month' => '2026-03-01',
        'task_weight' => 40, 'visit_weight' => 30, 'schedule_weight' => 20, 'work_time_weight' => 10,
    ]);
    SalesPlan::factory()->create([
        'employee_id' => $plan->employee_id,
        'month' => '2026-03-01',
        'active_month' => '2026-03-01',
    ]);

    expect(fn () => app(SalesPlanService::class)->transition($plan, SalesPlanStatus::Active))
        ->toThrow(DomainException::class, __('admin.employees.errors.plan_active_conflict'));
});
