<?php

declare(strict_types=1);

use App\Enums\PlanTaskStatus;
use App\Enums\SalesPlanStatus;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Services\Employees\SalesPlanDuplicationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('copies every documented field, excludes every documented field, and starts Draft with Pending tasks', function (): void {
    $activeCustomer = CustomerProfile::factory()->create(['is_active' => true]);
    $source = SalesPlan::factory()->create([
        'name' => 'January Plan',
        'month' => '2026-01-01',
        'task_weight' => 40, 'visit_weight' => 30, 'schedule_weight' => 20, 'work_time_weight' => 10,
        'required_visit_minutes' => 45,
    ]);
    $task = PlanTask::factory()->completed()->create([
        'sales_plan_id' => $source->id,
        'customer_id' => $activeCustomer->id,
        'title' => 'Visit the client',
        'starts_at' => '2026-01-05',
        'due_at' => '2026-01-10',
    ]);

    $targetEmployee = EmployeeProfile::factory()->create();

    $copy = app(SalesPlanDuplicationService::class)->duplicate(
        $source,
        $targetEmployee->id,
        CarbonImmutable::parse('2026-02-01'),
    );

    expect($copy->name)->toBe('January Plan')
        ->and($copy->employee_id)->toBe($targetEmployee->id)
        ->and((float) $copy->task_weight)->toBe(40.0)
        ->and($copy->required_visit_minutes)->toBe(45)
        ->and($copy->status)->toBe(SalesPlanStatus::Draft)
        ->and($copy->active_month)->toBeNull();

    $copiedTask = $copy->tasks->sole();

    expect($copiedTask->title)->toBe('Visit the client')
        ->and($copiedTask->customer_id)->toBe($activeCustomer->id)
        ->and($copiedTask->status)->toBe(PlanTaskStatus::Pending)
        ->and($copiedTask->completed_at)->toBeNull();
});

it('does not copy an inactive customer association', function (): void {
    $inactiveCustomer = CustomerProfile::factory()->create(['is_active' => false]);
    $source = SalesPlan::factory()->create(['month' => '2026-01-01']);
    PlanTask::factory()->create([
        'sales_plan_id' => $source->id,
        'customer_id' => $inactiveCustomer->id,
        'starts_at' => '2026-01-05',
        'due_at' => '2026-01-10',
    ]);
    $targetEmployee = EmployeeProfile::factory()->create();

    $copy = app(SalesPlanDuplicationService::class)->duplicate($source, $targetEmployee->id, CarbonImmutable::parse('2026-02-01'));

    expect($copy->tasks->sole()->customer_id)->toBeNull();
});

it('rejects the copy when the target employee already has an active plan for the target month, before any row is written', function (): void {
    $source = SalesPlan::factory()->withTasks(1)->create(['month' => '2026-01-01']);
    $targetEmployee = EmployeeProfile::factory()->create();
    SalesPlan::factory()->create([
        'employee_id' => $targetEmployee->id,
        'month' => '2026-02-01',
        'active_month' => '2026-02-01',
        'name' => 'Existing February Plan',
    ]);

    expect(fn () => app(SalesPlanDuplicationService::class)->duplicate(
        $source,
        $targetEmployee->id,
        CarbonImmutable::parse('2026-02-01'),
    ))->toThrow(DomainException::class, __('admin.employees.errors.plan_copy_target_conflict', ['plan' => 'Existing February Plan']));

    expect(SalesPlan::query()->where('employee_id', $targetEmployee->id)->count())->toBe(1);
});

it('clamps a task due on the 31st into a shorter target month instead of spilling into the next month', function (): void {
    $source = SalesPlan::factory()->create(['month' => '2026-01-01']);
    PlanTask::factory()->create([
        'sales_plan_id' => $source->id,
        'starts_at' => '2026-01-31',
        'due_at' => '2026-01-31',
    ]);
    $targetEmployee = EmployeeProfile::factory()->create();

    $copy = app(SalesPlanDuplicationService::class)->duplicate($source, $targetEmployee->id, CarbonImmutable::parse('2026-02-01'));

    $copiedTask = $copy->tasks->sole();

    expect($copiedTask->due_at->toDateString())->toBe('2026-02-28')
        ->and($copiedTask->starts_at->toDateString())->toBe('2026-02-28');
});

it('runs the whole copy in one transaction, leaving no partial plan or task on a forced failure', function (): void {
    $source = SalesPlan::factory()->create(['month' => '2026-01-01']);
    PlanTask::factory()->create(['sales_plan_id' => $source->id, 'starts_at' => '2026-01-05', 'due_at' => '2026-01-10']);
    $targetEmployee = EmployeeProfile::factory()->create();

    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'insert into "plan_tasks"')) {
            throw new RuntimeException('forced failure');
        }
    });

    try {
        app(SalesPlanDuplicationService::class)->duplicate($source, $targetEmployee->id, CarbonImmutable::parse('2026-02-01'));
    } catch (RuntimeException) {
        // expected
    }

    expect(SalesPlan::query()->where('employee_id', $targetEmployee->id)->count())->toBe(0);
});
