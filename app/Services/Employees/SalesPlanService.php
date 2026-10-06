<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\SalesPlanStatus;
use App\Events\SalesPlanPublished;
use App\Models\SalesPlan;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class SalesPlanService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): SalesPlan
    {
        return DB::transaction(function () use ($data): SalesPlan {
            $plan = SalesPlan::query()->create([
                ...$data,
                'active_month' => null,
                'status' => SalesPlanStatus::Draft,
            ]);

            activity()->performedOn($plan)
                ->withChanges(['attributes' => $plan->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('plan.created');

            return $plan;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(SalesPlan $plan, array $data): SalesPlan
    {
        return DB::transaction(function () use ($plan, $data): SalesPlan {
            $oldValues = $plan->getAttributes();
            $plan->update($data);

            $materialFields = [
                'employee_id', 'month', 'task_weight', 'visit_weight', 'schedule_weight',
                'work_time_weight', 'opportunity_weight', 'required_visit_minutes',
            ];
            $materialChanges = array_intersect_key(
                $plan->getChanges(),
                array_flip($materialFields),
            );

            activity()->performedOn($plan)
                ->withChanges([
                    'old' => Arr::only($oldValues, array_keys($plan->getChanges())),
                    'attributes' => $plan->getChanges(),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log($plan->published_at !== null && $materialChanges !== []
                    ? 'plan.materially_changed'
                    : 'plan.updated');

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

            if ($to === SalesPlanStatus::Published) {
                $this->guardPublish($plan);
            }

            if ($to === SalesPlanStatus::InProgress) {
                $this->guardStart($plan);
            }

            $plan->status = $to;
            $plan->active_month = $to === SalesPlanStatus::InProgress ? $plan->month : null;

            if ($to === SalesPlanStatus::Published) {
                $plan->published_at = now();
                $actorId = auth()->id();
                $plan->published_by = is_numeric($actorId) && (int) $actorId > 0
                    ? max(1, (int) $actorId)
                    : null;
            }

            $plan->save();

            activity()->performedOn($plan)
                ->withChanges([
                    'old' => ['status' => $from->value],
                    'attributes' => ['status' => $to->value],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log($to === SalesPlanStatus::Published ? 'plan.published' : 'plan.transitioned');

            if ($to === SalesPlanStatus::Published) {
                DB::afterCommit(static fn () => SalesPlanPublished::dispatch($plan->refresh()));
            }

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

            activity()->performedOn($plan)
                ->withChanges(['old' => $oldValues])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('plan.deleted');
        });
    }

    public function restore(SalesPlan $plan): SalesPlan
    {
        return DB::transaction(function () use ($plan): SalesPlan {
            $plan->restore();
            $plan->status = SalesPlanStatus::Archived;
            $plan->active_month = null;
            $plan->save();

            activity()->performedOn($plan)
                ->withChanges(['attributes' => $plan->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('plan.restored');

            return $plan;
        });
    }

    private function guardPublish(SalesPlan $plan): void
    {
        $this->guardWeights($plan);

        if ($plan->tasks()->count() === 0) {
            throw new DomainException(__('admin.employees.errors.plan_requires_at_least_one_task'));
        }
    }

    private function guardStart(SalesPlan $plan): void
    {
        $this->guardPublish($plan);

        $conflict = SalesPlan::query()
            ->where('employee_id', $plan->employee_id)
            ->where('active_month', $plan->month)
            ->where('id', '!=', $plan->id)
            ->exists();

        if ($conflict) {
            throw new DomainException(__('admin.employees.errors.plan_active_conflict'));
        }
    }

    private function guardWeights(SalesPlan $plan): void
    {
        $weightSum = (float) $plan->task_weight + (float) $plan->visit_weight
            + (float) $plan->schedule_weight + (float) $plan->work_time_weight
            + (float) $plan->opportunity_weight;

        if (abs($weightSum - 100.0) > 0.001) {
            throw new DomainException(__('admin.employees.errors.plan_weights_must_sum_to_100'));
        }
    }
}
