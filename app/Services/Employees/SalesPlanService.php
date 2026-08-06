<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\SalesPlanStatus;
use App\Models\SalesPlan;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class SalesPlanService
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SalesPlan
    {
        return DB::transaction(function () use ($data): SalesPlan {
            $plan = SalesPlan::query()->create([
                ...$data,
                'active_month' => null,
                'status' => SalesPlanStatus::Draft,
            ]);

            $this->auditLogger->log(
                action: 'plan.created',
                entity: $plan,
                newValues: $plan->getAttributes(),
            );

            return $plan;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SalesPlan $plan, array $data): SalesPlan
    {
        return DB::transaction(function () use ($plan, $data): SalesPlan {
            $oldValues = $plan->getAttributes();

            $plan->update($data);

            $this->auditLogger->log(
                action: 'plan.updated',
                entity: $plan,
                oldValues: $oldValues,
                newValues: $plan->getAttributes(),
            );

            return $plan;
        });
    }

    public function transition(SalesPlan $plan, SalesPlanStatus $to): SalesPlan
    {
        return DB::transaction(function () use ($plan, $to): SalesPlan {
            $from = $plan->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }

            if ($to === SalesPlanStatus::Active) {
                $this->guardActivation($plan);
            }

            $plan->status = $to;
            $plan->active_month = $to === SalesPlanStatus::Active ? $plan->month : null;
            $plan->save();

            $this->auditLogger->log(
                action: 'plan.transitioned',
                entity: $plan,
                oldValues: ['status' => $from->value],
                newValues: ['status' => $to->value],
            );

            return $plan;
        });
    }

    public function delete(SalesPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            if ($plan->tasks()->where('status', 'Completed')->exists()) {
                throw new DomainException(__('admin.employees.errors.plan_has_completed_tasks'));
            }

            $oldValues = $plan->getAttributes();

            $plan->delete();

            $this->auditLogger->log(
                action: 'plan.deleted',
                entity: $plan,
                oldValues: $oldValues,
            );
        });
    }

    public function restore(SalesPlan $plan): SalesPlan
    {
        return DB::transaction(function () use ($plan): SalesPlan {
            $plan->restore();
            $plan->status = SalesPlanStatus::Archived;
            $plan->active_month = null;
            $plan->save();

            $this->auditLogger->log(
                action: 'plan.restored',
                entity: $plan,
                newValues: $plan->getAttributes(),
            );

            return $plan;
        });
    }

    private function guardActivation(SalesPlan $plan): void
    {
        $weightSum = (float) $plan->task_weight + (float) $plan->visit_weight
            + (float) $plan->schedule_weight + (float) $plan->work_time_weight;

        if (abs($weightSum - 100.0) > 0.001) {
            throw new DomainException(__('admin.employees.errors.plan_weights_must_sum_to_100'));
        }

        if ($plan->tasks()->count() === 0) {
            throw new DomainException(__('admin.employees.errors.plan_requires_at_least_one_task'));
        }

        $conflict = SalesPlan::query()
            ->where('employee_id', $plan->employee_id)
            ->where('active_month', $plan->month)
            ->where('id', '!=', $plan->id)
            ->exists();

        if ($conflict) {
            throw new DomainException(__('admin.employees.errors.plan_active_conflict'));
        }
    }
}
