<?php

declare(strict_types=1);

use App\Enums\SalesPlanStatus;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Employees\SalesPlanDuplicationService;
use App\Services\Employees\SalesPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('writes an audit entry for create, update, transition, delete, and restore', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $service = app(SalesPlanService::class);
    $plan = $service->create([
        'employee_id' => EmployeeProfile::factory()->create()->id,
        'name' => 'Q1 Plan',
        'month' => '2026-03-01',
        'task_weight' => 40, 'visit_weight' => 30, 'schedule_weight' => 20, 'work_time_weight' => 10,
    ]);
    expect(AuditLog::query()->where('action', 'plan.created')->where('entity_id', $plan->id)->exists())->toBeTrue();

    $service->update($plan, ['name' => 'Q1 Plan Renamed']);
    expect(AuditLog::query()->where('action', 'plan.updated')->where('entity_id', $plan->id)->exists())->toBeTrue();

    PlanTask::factory()->create(['sales_plan_id' => $plan->id]);
    $service->transition($plan, SalesPlanStatus::Active);
    expect(AuditLog::query()->where('action', 'plan.transitioned')->where('entity_id', $plan->id)->exists())->toBeTrue();

    $service->transition($plan->fresh(), SalesPlanStatus::Completed);
    $service->delete($plan->fresh());

    expect(AuditLog::query()->where('action', 'plan.deleted')->where('entity_id', $plan->id)->exists())->toBeTrue();

    $service->restore(SalesPlan::withTrashed()->findOrFail($plan->id));
    expect(AuditLog::query()->where('action', 'plan.restored')->where('entity_id', $plan->id)->exists())->toBeTrue();
});

it('writes a single plan.copied entry per copy', function (): void {
    $source = SalesPlan::factory()->withTasks(2)->create(['month' => '2026-01-01']);
    $targetEmployee = EmployeeProfile::factory()->create();

    $copy = app(SalesPlanDuplicationService::class)->duplicate($source, $targetEmployee->id, CarbonImmutable::parse('2026-02-01'));

    expect(AuditLog::query()->where('action', 'plan.copied')->where('entity_id', $copy->id)->count())->toBe(1);
});

it('discards the audit row when the enclosing transaction rolls back', function (): void {
    $plan = SalesPlan::factory()->create();

    try {
        DB::transaction(function () use ($plan): void {
            app(SalesPlanService::class)->update($plan, ['name' => 'Should not persist']);

            throw new RuntimeException('forced rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($plan->fresh()->name)->not->toBe('Should not persist')
        ->and(AuditLog::query()->where('action', 'plan.updated')->where('entity_id', $plan->id)->exists())->toBeFalse();
});
