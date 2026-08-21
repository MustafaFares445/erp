<?php

declare(strict_types=1);

use App\Models\BonusSuggestion;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Services\Employees\SalaryCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('resolves payable_base from base_salary when use_base_salary is true', function (): void {
    $employee = EmployeeProfile::factory()->baseSalary()->create(['base_salary' => 6000]);
    $plan = SalesPlan::factory()->withTasks(1)->create(['employee_id' => $employee->id]);

    $calculation = app(SalaryCalculationService::class)->calculate($plan);

    expect((float) $calculation->payable_base)->toBe(6000.0);
});

it('resolves payable_base from commission_target_amount when use_base_salary is false', function (): void {
    $employee = EmployeeProfile::factory()->performanceOnly()->create(['commission_target_amount' => 4500]);
    $plan = SalesPlan::factory()->withTasks(1)->create(['employee_id' => $employee->id]);

    $calculation = app(SalaryCalculationService::class)->calculate($plan);

    expect((float) $calculation->payable_base)->toBe(4500.0);
});

it('fails validation rather than defaulting to zero when the payable base is null', function (): void {
    $employee = EmployeeProfile::factory()->baseSalary()->create(['base_salary' => 6000]);
    // A raw update bypasses the model's own saving guard, simulating data
    // that reached this null state some other way (import, migration, a
    // bug elsewhere) — the service must still refuse to treat it as zero.
    DB::table('employee_profiles')->where('id', $employee->id)->update(['base_salary' => null]);
    $plan = SalesPlan::factory()->withTasks(1)->create(['employee_id' => $employee->id]);

    expect(fn () => app(SalaryCalculationService::class)->calculate($plan->fresh()))
        ->toThrow(DomainException::class, __('admin.employees.errors.missing_base_salary'));
});

it('sums only Approved bonus suggestions into bonus_amount', function (): void {
    $employee = EmployeeProfile::factory()->baseSalary()->create(['base_salary' => 5000]);
    $plan = SalesPlan::factory()->withTasks(1)->create(['employee_id' => $employee->id]);
    PlanTask::query()->where('sales_plan_id', $plan->id)->first()->update(['status' => 'Completed', 'completed_at' => now()]);

    BonusSuggestion::factory()->approved()->create(['employee_id' => $employee->id, 'sales_plan_id' => $plan->id, 'amount' => 100]);
    BonusSuggestion::factory()->create(['employee_id' => $employee->id, 'sales_plan_id' => $plan->id, 'amount' => 200]); // Pending
    BonusSuggestion::factory()->rejected()->create(['employee_id' => $employee->id, 'sales_plan_id' => $plan->id, 'amount' => 300]);
    BonusSuggestion::factory()->approved()->create(['employee_id' => $employee->id, 'sales_plan_id' => $plan->id, 'amount' => 50]);

    $calculation = app(SalaryCalculationService::class)->calculate($plan);

    expect((float) $calculation->bonus_amount)->toBe(150.0);
});
