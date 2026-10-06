<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\VisitStatus;
use App\Events\VisitAssigned;
use App\Events\VisitRescheduled;
use App\Models\CustomerVisit;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Services\Employees\Exceptions\VisitScheduleConflict;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class VisitSchedulingService
{
    /** @param array<string, mixed> $data */
    public function schedule(array $data, bool $overrideConflict = false, ?string $overrideReason = null): CustomerVisit
    {
        return DB::transaction(function () use ($data, $overrideConflict, $overrideReason): CustomerVisit {
            $employeeId = $this->integer($data, 'employee_id');
            $taskId = $this->integer($data, 'plan_task_id');
            $customerId = $this->integer($data, 'customer_id');
            $start = $this->dateTime($data, 'scheduled_start_at');
            $end = $this->dateTime($data, 'scheduled_end_at');

            $this->validateWindow($start, $end);
            $this->validateTaskAssignment($taskId, $employeeId, $customerId);
            $this->assertNoConflict($employeeId, $start, $end, null, $overrideConflict, $overrideReason);

            $visit = CustomerVisit::query()->create([
                ...$data,
                'reference' => $this->nextReference(),
                'planned_at' => $start,
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
                'schedule_override_reason' => $overrideConflict ? mb_trim((string) $overrideReason) : null,
                'schedule_overridden_by' => $overrideConflict ? auth()->id() : null,
                'schedule_overridden_at' => $overrideConflict ? now() : null,
                'status' => VisitStatus::Scheduled,
            ]);

            activity()->performedOn($visit)
                ->withChanges(['attributes' => $visit->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log($overrideConflict ? 'visit.scheduled_with_conflict_override' : 'visit.scheduled');

            DB::afterCommit(static fn () => VisitAssigned::dispatch($visit->refresh()));

            return $visit;
        });
    }

    public function reschedule(
        CustomerVisit $visit,
        Carbon $start,
        Carbon $end,
        bool $overrideConflict = false,
        ?string $overrideReason = null,
    ): CustomerVisit {
        return DB::transaction(function () use ($visit, $start, $end, $overrideConflict, $overrideReason): CustomerVisit {
            if ($visit->isTerminal()) {
                throw new DomainException('A terminal visit cannot be rescheduled.');
            }

            $this->validateWindow($start, $end);
            $this->assertNoConflict(
                $visit->employee_id,
                $start,
                $end,
                $visit->id,
                $overrideConflict,
                $overrideReason,
            );

            $old = $visit->only(['scheduled_start_at', 'scheduled_end_at']);
            $visit->update([
                'planned_at' => $start,
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
                'schedule_override_reason' => $overrideConflict ? mb_trim((string) $overrideReason) : null,
                'schedule_overridden_by' => $overrideConflict ? auth()->id() : null,
                'schedule_overridden_at' => $overrideConflict ? now() : null,
            ]);

            activity()->performedOn($visit)
                ->withChanges([
                    'old' => $old,
                    'attributes' => $visit->only(['scheduled_start_at', 'scheduled_end_at']),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log($overrideConflict ? 'visit.schedule_conflict_overridden' : 'visit.rescheduled');

            DB::afterCommit(static fn () => VisitRescheduled::dispatch($visit->refresh()));

            return $visit;
        });
    }

    /** @return Collection<int, CustomerVisit> */
    public function conflictsFor(int $employeeId, Carbon $start, Carbon $end, ?int $ignoreVisitId = null): Collection
    {
        $query = CustomerVisit::query()
            ->where('employee_id', $employeeId)
            ->whereNotIn('status', [
                VisitStatus::Completed->value,
                VisitStatus::UnableToComplete->value,
                VisitStatus::Cancelled->value,
            ])
            ->whereNotNull('scheduled_start_at')
            ->whereNotNull('scheduled_end_at')
            ->where('scheduled_start_at', '<', $end)
            ->where('scheduled_end_at', '>', $start);

        if ($ignoreVisitId !== null) {
            $query->whereKeyNot($ignoreVisitId);
        }

        return $query->get();
    }

    private function assertNoConflict(
        int $employeeId,
        Carbon $start,
        Carbon $end,
        ?int $ignoreVisitId,
        bool $overrideConflict,
        ?string $overrideReason,
    ): void {
        $conflicts = $this->conflictsFor($employeeId, $start, $end, $ignoreVisitId);

        if ($conflicts->isEmpty()) {
            return;
        }

        if (! $overrideConflict) {
            $conflictingVisitIds = array_values(array_map(
                static fn (int|string $id): int => (int) $id,
                $conflicts->modelKeys(),
            ));

            throw new VisitScheduleConflict($conflictingVisitIds);
        }

        if (mb_trim((string) $overrideReason) === '') {
            throw new DomainException('A schedule conflict override reason is required.');
        }
    }

    private function validateTaskAssignment(int $taskId, int $employeeId, int $customerId): void
    {
        $task = PlanTask::query()->with('salesPlan')->findOrFail($taskId);
        $plan = $task->salesPlan;

        if (! $plan instanceof SalesPlan || $plan->employee_id !== $employeeId) {
            throw new DomainException('The selected task does not belong to the selected employee.');
        }

        if ($task->customer_id !== null && $task->customer_id !== $customerId) {
            throw new DomainException('The visit customer must match the task customer.');
        }
    }

    private function validateWindow(Carbon $start, Carbon $end): void
    {
        if ($end->lessThanOrEqualTo($start)) {
            throw new DomainException('The scheduled end must be after the scheduled start.');
        }
    }

    /** @param array<string, mixed> $data */
    private function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_numeric($value)) {
            throw new LogicException("Expected {$key}.");
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $data */
    private function dateTime(array $data, string $key): Carbon
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) && ! $value instanceof \DateTimeInterface) {
            throw new LogicException("Expected {$key}.");
        }

        return Carbon::parse($value);
    }

    private function nextReference(): string
    {
        do {
            $reference = 'VIS-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (CustomerVisit::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
