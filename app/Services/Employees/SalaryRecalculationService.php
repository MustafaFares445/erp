<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\SalaryCalculationStatus;
use App\Jobs\NotifyAdminOfSalaryRecalculation;
use App\Models\EmployeeSalaryCalculation;
use App\Models\SalesPlan;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use Illuminate\Support\Facades\DB;

/**
 * On plan change: builds a new `PendingConfirmation` calculation, marks the
 * prior `Confirmed` one `Superseded` in the same transaction (never
 * deleted), then queues the admin notification (FR-065, H2, FR-082).
 */
final readonly class SalaryRecalculationService
{
    public function __construct(
        private SalaryCalculationService $salaryCalculationService,
    ) {}

    public function recalculate(SalesPlan $plan): EmployeeSalaryCalculation
    {
        return DB::transaction(function () use ($plan): EmployeeSalaryCalculation {
            $previous = EmployeeSalaryCalculation::query()
                ->where('sales_plan_id', $plan->id)
                ->where('employee_id', $plan->employee_id)
                ->where('status', SalaryCalculationStatus::Confirmed)
                ->latest('id')
                ->first();

            $new = $this->salaryCalculationService->calculate($plan);

            if ($previous instanceof EmployeeSalaryCalculation) {
                $previous->update([
                    'status' => SalaryCalculationStatus::Superseded,
                    'superseded_by_id' => $new->id,
                    'superseded_at' => now(),
                ]);

                activity()
                    ->performedOn($previous)
                    ->withChanges([
                        'attributes' => $previous->getAttributes(),
                    ])
                    ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                    ->log('salary.superseded');
            }

            NotifyAdminOfSalaryRecalculation::dispatch($new->id);

            return $new;
        });
    }

    public function confirm(EmployeeSalaryCalculation $calculation): EmployeeSalaryCalculation
    {
        return DB::transaction(function () use ($calculation): EmployeeSalaryCalculation {
            $from = $calculation->status;

            if (! $from->canTransitionTo(SalaryCalculationStatus::Confirmed)) {
                throw InvalidStatusTransition::fromTo($from->value, SalaryCalculationStatus::Confirmed->value);
            }

            $calculation->update([
                'status' => SalaryCalculationStatus::Confirmed,
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
            ]);

            activity()
                ->performedOn($calculation)
                ->withChanges([
                    'attributes' => $calculation->getAttributes(),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('salary.confirmed');

            return $calculation;
        });
    }
}
