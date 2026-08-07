<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\PlanTaskStatus;
use App\Enums\VisitStatus;
use App\Models\CustomerVisit;
use App\Models\EmployeePerformanceScore;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\Data\PerformanceScoreInputs;
use App\Services\Employees\Data\PerformanceScoreResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Computes the four weighted component scores and the total score per plan
 * (contracts/performance-scoring.md, D2, D4, D5). {@see self::calculate()}
 * is pure and deterministic — no database access, so it is unit-testable in
 * isolation. {@see self::scoreForPlan()} gathers the real inputs and
 * persists the result.
 */
final readonly class PerformanceScoringService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function calculate(PerformanceScoreInputs $inputs): PerformanceScoreResult
    {
        $taskRatio = $this->ratio($inputs->completedTasks, $inputs->totalTasks);
        $visitRatio = $this->ratio($inputs->completedVisits, $inputs->totalVisits);
        $scheduleRatio = $this->ratio($inputs->onTimeCompletedTasks, $inputs->completedTasks);
        $workTimeRatio = $this->ratio($inputs->durationCompliantVisits, $inputs->completedVisits);

        $taskScore = round($taskRatio * $inputs->taskWeight, 2);
        $visitScore = round($visitRatio * $inputs->visitWeight, 2);
        $scheduleScore = round($scheduleRatio * $inputs->scheduleWeight, 2);
        $workTimeScore = round($workTimeRatio * $inputs->workTimeWeight, 2);

        $breakdown = [
            'task_completion' => $this->factorBreakdown($inputs->completedTasks, $inputs->totalTasks, $taskRatio, $inputs->taskWeight, $taskScore),
            'visit_completion' => [
                ...$this->factorBreakdown($inputs->completedVisits, $inputs->totalVisits, $visitRatio, $inputs->visitWeight, $visitScore),
                'unattributed_visit_count' => $inputs->unattributedVisitCount,
            ],
            'schedule_adherence' => $this->factorBreakdown($inputs->onTimeCompletedTasks, $inputs->completedTasks, $scheduleRatio, $inputs->scheduleWeight, $scheduleScore),
            'work_time_adherence' => [
                ...$this->factorBreakdown($inputs->durationCompliantVisits, $inputs->completedVisits, $workTimeRatio, $inputs->workTimeWeight, $workTimeScore),
                'required_visit_minutes' => $inputs->requiredVisitMinutes,
                'missing_timestamp_visit_count' => $inputs->visitsMissingTimestamps,
                'unattributed_visit_count' => $inputs->unattributedVisitCount,
            ],
        ];

        return new PerformanceScoreResult(
            taskScore: $taskScore,
            visitScore: $visitScore,
            scheduleScore: $scheduleScore,
            workTimeScore: $workTimeScore,
            totalScore: round($taskScore + $visitScore + $scheduleScore + $workTimeScore, 2),
            taskCompletionPercent: round($taskRatio * 100, 2),
            breakdown: $breakdown,
        );
    }

    public function scoreForPlan(SalesPlan $plan): EmployeePerformanceScore
    {
        $result = $this->calculate($this->gatherInputs($plan));

        return DB::transaction(function () use ($plan, $result): EmployeePerformanceScore {
            $score = EmployeePerformanceScore::query()->updateOrCreate(
                ['sales_plan_id' => $plan->id, 'employee_id' => $plan->employee_id],
                [
                    'task_score' => $result->taskScore,
                    'visit_score' => $result->visitScore,
                    'schedule_score' => $result->scheduleScore,
                    'work_time_score' => $result->workTimeScore,
                    'total_score' => $result->totalScore,
                    'task_completion_percent' => $result->taskCompletionPercent,
                    'calculation_breakdown' => $result->breakdown,
                    'calculated_at' => now(),
                ],
            );

            $this->auditLogger->log(
                action: 'performance.calculated',
                entity: $score,
                newValues: $score->getAttributes(),
            );

            return $score;
        });
    }

    private function gatherInputs(SalesPlan $plan): PerformanceScoreInputs
    {
        $tasks = PlanTask::query()->where('sales_plan_id', $plan->id)->get();
        $completedTasks = $tasks->where('status', PlanTaskStatus::Completed);
        $onTimeCompletedTasks = $completedTasks->filter(
            fn (PlanTask $task): bool => self::isTaskOnTime($task),
        );

        $visits = CustomerVisit::query()
            ->whereHas('planTask', fn (Builder $query): Builder => $query->where('sales_plan_id', $plan->id))
            ->get();
        $completedVisits = $visits->where('status', VisitStatus::Completed);
        $durationCompliantVisits = $completedVisits->filter(
            fn (CustomerVisit $visit): bool => $visit->durationMinutes() !== null
                && $visit->durationMinutes() >= $plan->requiredVisitMinutes(),
        );
        $visitsMissingTimestamps = $completedVisits->filter(
            fn (CustomerVisit $visit): bool => $visit->durationMinutes() === null,
        );

        return new PerformanceScoreInputs(
            totalTasks: $tasks->count(),
            completedTasks: $completedTasks->count(),
            onTimeCompletedTasks: $onTimeCompletedTasks->count(),
            totalVisits: $visits->count(),
            completedVisits: $completedVisits->count(),
            durationCompliantVisits: $durationCompliantVisits->count(),
            visitsMissingTimestamps: $visitsMissingTimestamps->count(),
            unattributedVisitCount: $this->unattributedVisitCount($plan),
            requiredVisitMinutes: $plan->requiredVisitMinutes(),
            taskWeight: (float) $plan->task_weight,
            visitWeight: (float) $plan->visit_weight,
            scheduleWeight: (float) $plan->schedule_weight,
            workTimeWeight: (float) $plan->work_time_weight,
        );
    }

    private function unattributedVisitCount(SalesPlan $plan): int
    {
        $monthStart = Carbon::parse($plan->month)->startOfMonth();
        $monthEnd = Carbon::parse($plan->month)->endOfMonth();

        return CustomerVisit::query()
            ->where('employee_id', $plan->employee_id)
            ->whereNull('plan_task_id')
            ->whereBetween('checked_in_at', [$monthStart, $monthEnd])
            ->count();
    }

    /**
     * "On time" per contracts/performance-scoring.md: `completed_at <= due_at`,
     * inclusive of equality, compared by calendar day since `due_at` has no
     * time component. Pure — reads only the two attributes already on the
     * model, so it needs no database connection to unit test.
     */
    public static function isTaskOnTime(PlanTask $task): bool
    {
        return $task->completed_at !== null
            && $task->completed_at->toDateString() <= $task->due_at->toDateString();
    }

    private function ratio(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : $numerator / $denominator;
    }

    /**
     * @return array{numerator: int, denominator: int, ratio: float, weight: float, contribution: float}
     */
    private function factorBreakdown(int $numerator, int $denominator, float $ratio, float $weight, float $contribution): array
    {
        return [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'ratio' => round($ratio, 4),
            'weight' => $weight,
            'contribution' => $contribution,
        ];
    }
}
