<?php

declare(strict_types=1);

use App\Enums\SalaryCalculationStatus;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use App\Services\Employees\SalaryCalculationService;
use App\Services\Employees\SalaryRecalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('marks the prior confirmed calculation Superseded, never deleted, and requires a fresh confirmation', function (): void {
    $employee = EmployeeProfile::factory()->baseSalary()->create(['base_salary' => 5000]);
    $plan = SalesPlan::factory()->withTasks(1)->create(['employee_id' => $employee->id]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $first = app(SalaryCalculationService::class)->calculate($plan);
    app(SalaryRecalculationService::class)->confirm($first);
    expect($first->fresh()->status)->toBe(SalaryCalculationStatus::Confirmed);

    $second = app(SalaryRecalculationService::class)->recalculate($plan);

    expect($first->fresh()->status)->toBe(SalaryCalculationStatus::Superseded)
        ->and($first->fresh()->superseded_by_id)->toBe($second->id)
        ->and($first->fresh()->superseded_at)->not->toBeNull()
        ->and(EmployeeSalaryCalculation::query()->find($first->id))->not->toBeNull()
        ->and($second->status)->toBe(SalaryCalculationStatus::PendingConfirmation);

    app(SalaryRecalculationService::class)->confirm($second);
    expect($second->fresh()->status)->toBe(SalaryCalculationStatus::Confirmed);
});

it('relates a superseded calculation to the row that replaced it', function (): void {
    $employee = EmployeeProfile::factory()->baseSalary()->create(['base_salary' => 5000]);
    $plan = SalesPlan::factory()->withTasks(1)->create(['employee_id' => $employee->id]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $first = app(SalaryCalculationService::class)->calculate($plan);
    app(SalaryRecalculationService::class)->confirm($first);
    $second = app(SalaryRecalculationService::class)->recalculate($plan);

    expect($first->fresh()->supersededBy?->id)->toBe($second->id);
});

it('rejects confirming an already-superseded calculation', function (): void {
    $calculation = EmployeeSalaryCalculation::factory()->superseded()->create();

    expect(fn () => app(SalaryRecalculationService::class)->confirm($calculation))
        ->toThrow(InvalidStatusTransition::class);
});

it('runs recalculation-with-supersession in one transaction, leaving no partial write on a forced failure', function (): void {
    $employee = EmployeeProfile::factory()->baseSalary()->create(['base_salary' => 5000]);
    $plan = SalesPlan::factory()->withTasks(1)->create(['employee_id' => $employee->id]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $first = app(SalaryCalculationService::class)->calculate($plan);
    app(SalaryRecalculationService::class)->confirm($first);

    $countBefore = EmployeeSalaryCalculation::query()->count();

    DB::listen(function ($query): void {
        if (str_contains((string) $query->sql, 'update "employee_salary_calculations" set "status"')) {
            throw new RuntimeException('forced failure during supersession');
        }
    });

    try {
        app(SalaryRecalculationService::class)->recalculate($plan);
    } catch (RuntimeException) {
        // expected
    }

    expect(EmployeeSalaryCalculation::query()->count())->toBe($countBefore)
        ->and($first->fresh()->status)->toBe(SalaryCalculationStatus::Confirmed);
});

it('keeps a confirmed calculation reproducible from its own row after the employee profile later changes', function (): void {
    $employee = EmployeeProfile::factory()->baseSalary()->create(['base_salary' => 5000]);
    $plan = SalesPlan::factory()->withTasks(1)->create(['employee_id' => $employee->id]);
    $task = PlanTask::query()->where('sales_plan_id', $plan->id)->first();
    $task->update(['status' => 'Completed', 'completed_at' => now()]);

    $calculation = app(SalaryCalculationService::class)->calculate($plan->fresh());
    $originalPayableBase = (float) $calculation->payable_base;
    $originalFinalSalary = (float) $calculation->final_salary;

    $employee->update(['base_salary' => 9999]);

    $calculation->refresh();

    expect((float) $calculation->payable_base)->toBe($originalPayableBase)
        ->and((float) $calculation->final_salary)->toBe($originalFinalSalary);
});
