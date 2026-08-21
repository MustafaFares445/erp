<?php

declare(strict_types=1);

use App\Enums\SalesPlanStatus;
use App\Models\SalesPlan;
use App\Services\Employees\SalesPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects activation unless the four weights sum to exactly 100', function (): void {
    $plan = SalesPlan::factory()->withTasks(1)->create([
        'task_weight' => 40,
        'visit_weight' => 30,
        'schedule_weight' => 20,
        'work_time_weight' => 5,
    ]);

    expect(fn () => app(SalesPlanService::class)->transition($plan, SalesPlanStatus::Active))
        ->toThrow(DomainException::class, __('admin.employees.errors.plan_weights_must_sum_to_100'));

    expect($plan->fresh()->status)->toBe(SalesPlanStatus::Draft);
});

it('rejects activation when the plan has no tasks', function (): void {
    $plan = SalesPlan::factory()->create();

    expect(fn () => app(SalesPlanService::class)->transition($plan, SalesPlanStatus::Active))
        ->toThrow(DomainException::class, __('admin.employees.errors.plan_requires_at_least_one_task'));
});

it('activates a plan whose weights sum to 100 and has at least one task', function (): void {
    $plan = SalesPlan::factory()->withTasks(2)->create();

    $activated = app(SalesPlanService::class)->transition($plan, SalesPlanStatus::Active);

    expect($activated->status)->toBe(SalesPlanStatus::Active)
        ->and($activated->active_month->toDateString())->toBe($plan->month->toDateString());
});
