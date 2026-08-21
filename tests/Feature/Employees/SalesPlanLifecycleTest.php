<?php

declare(strict_types=1);

use App\Enums\SalesPlanStatus;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Services\Employees\SalesPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('blocks deletion once any task on the plan has been completed', function (): void {
    $plan = SalesPlan::factory()->create();
    PlanTask::factory()->completed()->create(['sales_plan_id' => $plan->id]);

    expect(fn () => app(SalesPlanService::class)->delete($plan))
        ->toThrow(DomainException::class, __('admin.employees.errors.plan_has_completed_tasks'));

    expect(SalesPlan::query()->find($plan->id))->not->toBeNull();
});

it('allows deletion when no task on the plan has been completed', function (): void {
    $plan = SalesPlan::factory()->create();
    PlanTask::factory()->create(['sales_plan_id' => $plan->id]);

    app(SalesPlanService::class)->delete($plan);

    expect(SalesPlan::query()->find($plan->id))->toBeNull()
        ->and(SalesPlan::withTrashed()->find($plan->id))->not->toBeNull();
});

it('restores a soft-deleted plan back to Archived, never to Active, regardless of its prior status', function (): void {
    $plan = SalesPlan::factory()->withTasks(1)->create();
    app(SalesPlanService::class)->transition($plan, SalesPlanStatus::Active);
    app(SalesPlanService::class)->delete($plan->fresh());

    $restored = app(SalesPlanService::class)->restore(SalesPlan::withTrashed()->findOrFail($plan->id));

    expect($restored->trashed())->toBeFalse()
        ->and($restored->status)->toBe(SalesPlanStatus::Archived)
        ->and($restored->active_month)->toBeNull();
});
