<?php

declare(strict_types=1);

use App\Models\SalesPlan;
use App\Services\Employees\PlanTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects a task whose starts_at falls outside the plan month window', function (): void {
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);

    expect(fn () => app(PlanTaskService::class)->create($plan, [
        'title' => 'Out of window',
        'starts_at' => '2026-02-28',
        'due_at' => '2026-03-05',
    ]))->toThrow(DomainException::class, __('admin.employees.errors.task_dates_outside_plan_window'));
});

it('rejects a task whose due_at falls outside the plan month window', function (): void {
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);

    expect(fn () => app(PlanTaskService::class)->create($plan, [
        'title' => 'Out of window',
        'starts_at' => '2026-03-05',
        'due_at' => '2026-04-01',
    ]))->toThrow(DomainException::class);
});

it('accepts a task whose dates fall inside the plan month window', function (): void {
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);

    $task = app(PlanTaskService::class)->create($plan, [
        'title' => 'Inside window',
        'starts_at' => '2026-03-01',
        'due_at' => '2026-03-31',
    ]);

    expect($task->exists)->toBeTrue();
});

it('rejects an update that moves the dates outside the plan window', function (): void {
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);
    $task = app(PlanTaskService::class)->create($plan, [
        'title' => 'Inside window',
        'starts_at' => '2026-03-05',
        'due_at' => '2026-03-10',
    ]);

    expect(fn () => app(PlanTaskService::class)->update($task, ['due_at' => '2026-04-01']))
        ->toThrow(DomainException::class);
});
