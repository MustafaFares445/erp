<?php

declare(strict_types=1);

use App\Enums\SalesPlanStatus;
use App\Models\SalesPlan;
use App\Services\Employees\SalesPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects publishing unless the configured weights sum to exactly 100', function (): void {
    $plan = SalesPlan::factory()->withTasks(1)->create([
        'task_weight' => 40,
        'visit_weight' => 30,
        'schedule_weight' => 20,
        'work_time_weight' => 5,
        'opportunity_weight' => 0,
    ]);

    expect(fn () => app(SalesPlanService::class)->transition($plan, SalesPlanStatus::Published))
        ->toThrow(DomainException::class, __('admin.employees.errors.plan_weights_must_sum_to_100'));

    expect($plan->fresh()->status)->toBe(SalesPlanStatus::Draft);
});

it('rejects publishing when the plan has no tasks', function (): void {
    $plan = SalesPlan::factory()->create();

    expect(fn () => app(SalesPlanService::class)->transition($plan, SalesPlanStatus::Published))
        ->toThrow(DomainException::class, __('admin.employees.errors.plan_requires_at_least_one_task'));
});

it('publishes and starts a valid plan through the explicit lifecycle', function (): void {
    $plan = SalesPlan::factory()->withTasks(2)->create();

    $published = app(SalesPlanService::class)->transition($plan, SalesPlanStatus::Published);
    $started = app(SalesPlanService::class)->transition($published->refresh(), SalesPlanStatus::InProgress);

    expect($published->published_at)->not->toBeNull()
        ->and($started->status)->toBe(SalesPlanStatus::InProgress)
        ->and($started->active_month->toDateString())->toBe($plan->month->toDateString());
});
