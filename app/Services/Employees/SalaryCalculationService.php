<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\BonusSuggestionStatus;
use App\Enums\SalaryCalculationStatus;
use App\Models\BonusSuggestion;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\SalesPlan;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Resolves `payable_base`, computes `performance_percent`/`bonus_amount`/
 * `final_salary`, and persists a new `PendingConfirmation` calculation
 * (contracts/performance-scoring.md §Salary, D2, D3, FR-062, FR-063).
 */
final readonly class SalaryCalculationService
{
    public function __construct(
        private PerformanceScoringService $performanceScoringService,
    ) {}

    public function calculate(SalesPlan $plan): EmployeeSalaryCalculation
    {
        return DB::transaction(function () use ($plan): EmployeeSalaryCalculation {
            $employee = $plan->employee;

            if (! $employee instanceof EmployeeProfile) {
                throw new DomainException('A sales plan must belong to an employee profile.');
            }

            $payableBase = $this->resolvePayableBase($employee);
            $score = $this->performanceScoringService->scoreForPlan($plan);
            $bonusAmount = (float) BonusSuggestion::query()
                ->where('sales_plan_id', $plan->id)
                ->where('employee_id', $plan->employee_id)
                ->where('status', BonusSuggestionStatus::Approved)
                ->sum('amount');

            $finalSalary = round($payableBase * ((float) $score->total_score / 100) + $bonusAmount, 2);

            $calculation = new EmployeeSalaryCalculation;
            $calculation->fill([
                'sales_plan_id' => $plan->id,
                'employee_id' => $plan->employee_id,
                'performance_score_id' => $score->getKey(),
                'status' => SalaryCalculationStatus::PendingConfirmation,
                'use_base_salary_snapshot' => $employee->use_base_salary,
                'base_salary_snapshot' => $employee->base_salary,
                'salary_calculation_mode_snapshot' => $employee->salary_calculation_mode->value,
                'calculation_explanation' => [
                    'payable_base' => $payableBase,
                    'performance_percent' => (float) $score->total_score,
                    'performance_snapshot_id' => $score->getKey(),
                    'approved_bonus_amount' => $bonusAmount,
                    'formula' => 'payable_base × performance_percent / 100 + approved_bonus_amount',
                ],
            ]);
            $calculation->payable_base = $payableBase;
            $calculation->performance_percent = $score->total_score;
            $calculation->bonus_amount = $bonusAmount;
            $calculation->final_salary = $finalSalary;
            $calculation->save();

            activity()
                ->performedOn($calculation)
                ->withChanges([
                    'attributes' => $calculation->getAttributes(),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('salary.calculated');

            return $calculation;
        });
    }

    private function resolvePayableBase(EmployeeProfile $employee): float
    {
        $base = $employee->use_base_salary ? $employee->base_salary : $employee->commission_target_amount;

        if ($base === null) {
            throw new DomainException($employee->use_base_salary
                ? __('admin.employees.errors.missing_base_salary')
                : __('admin.employees.errors.missing_commission_target'));
        }

        return (float) $base;
    }
}
