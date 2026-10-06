<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\PlanTaskStatus;
use App\Enums\VisitStatus;
use App\Models\CustomerVisit;
use App\Models\EmployeePerformanceScore;
use App\Models\PlanTask;
use App\Models\SalesOpportunity;
use App\Models\SalesPlan;
use App\Services\Employees\Data\PerformanceScoreInputs;
use App\Services\Employees\Data\PerformanceScoreResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class PerformanceScoringService
{
    public function calculate(PerformanceScoreInputs $inputs): PerformanceScoreResult
    {
        $taskRatio = $this->ratio($inputs->completedTasks, $inputs->totalTasks);
        $visitRatio = $this->ratio($inputs->completedVisits, $inputs->totalVisits);
        $scheduleRatio = $this->ratio($inputs->onTimeCompletedTasks, $inputs->completedTasks);
        $workTimeRatio = $this->ratio($inputs->durationCompliantVisits, $inputs->completedVisits);
        $opportunityRatio = min(1.0, $this->ratio($inputs->detectedOpportunities, $inputs->completedVisits));

        $taskScore = round($taskRatio * $inputs->taskWeight, 2);
        $visitScore = round($visitRatio * $inputs->visitWeight, 2);
        $scheduleScore = round($scheduleRatio * $inputs->scheduleWeight, 2);
        $workTimeScore = round($workTimeRatio * $inputs->workTimeWeight, 2);
        $opportunityScore = round($opportunityRatio * $inputs->opportunityWeight, 2);

        $breakdown = [
            'task_completion' => $this->factorBreakdown($inputs->completedTasks, $inputs->totalTasks, $taskRatio, $inputs->taskWeight, $taskScore),
            'visit_completion' => $this->factorBreakdown($inputs->completedVisits, $inputs->totalVisits, $visitRatio, $inputs->visitWeight, $visitScore),
            'schedule_adherence' => $this->factorBreakdown($inputs->onTimeCompletedTasks, $inputs->completedTasks, $scheduleRatio, $inputs->scheduleWeight, $scheduleScore),
            'work_time_adherence' => [
                ...$this->factorBreakdown($inputs->durationCompliantVisits, $inputs->completedVisits, $workTimeRatio, $inputs->workTimeWeight, $workTimeScore),
                'required_visit_minutes' => $inputs->requiredVisitMinutes,
                'missing_timestamp_visit_count' => $inputs->visitsMissingTimestamps,
            ],
            'potential_sales_opportunities' => [
                ...$this->factorBreakdown($inputs->detectedOpportunities, $inputs->completedVisits, $opportunityRatio, $inputs->opportunityWeight, $opportunityScore),
                'rule' => 'Detected opportunities per completed visit, capped at 100%.',
            ],
        ];

        return new PerformanceScoreResult(
            taskScore: $taskScore,
            visitScore: $visitScore,
            scheduleScore: $scheduleScore,
            workTimeScore: $workTimeScore,
            opportunityScore: $opportunityScore,
            totalScore: round($taskScore + $visitScore + $scheduleScore + $workTimeScore + $opportunityScore, 2),
            taskCompletionPercent: round($taskRatio * 100, 2),
            breakdown: $breakdown,
        );
    }

    public function scoreForPlan(SalesPlan $plan): EmployeePerformanceScore
    {
        $inputs = $this->gatherInputs($plan);
        $result = $this->calculate($inputs);
        $periodStart = $plan->month->copy()->startOfMonth();
        $periodEnd = $plan->month->copy()->endOfMonth();

        return DB::transaction(function () use ($plan, $inputs, $result, $periodStart, $periodEnd): EmployeePerformanceScore {
            $score = EmployeePerformanceScore::query()->create([
                'sales_plan_id' => $plan->id,
                'employee_id' => $plan->employee_id,
                'task_score' => $result->taskScore,
                'visit_score' => $result->visitScore,
                'schedule_score' => $result->scheduleScore,
                'work_time_score' => $result->workTimeScore,
                'opportunity_score' => $result->opportunityScore,
                'total_score' => $result->totalScore,
                'task_completion_percent' => $result->taskCompletionPercent,
                'calculation_breakdown' => $result->breakdown,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'factor_weights' => [
                    'task_completion' => $inputs->taskWeight,
                    'visit_completion' => $inputs->visitWeight,
                    'schedule_adherence' => $inputs->scheduleWeight,
                    'work_time_adherence' => $inputs->workTimeWeight,
                    'potential_sales_opportunities' => $inputs->opportunityWeight,
                ],
                'raw_values' => [
                    'total_tasks' => $inputs->totalTasks,
                    'completed_tasks' => $inputs->completedTasks,
                    'on_time_completed_tasks' => $inputs->onTimeCompletedTasks,
                    'total_visits' => $inputs->totalVisits,
                    'completed_visits' => $inputs->completedVisits,
                    'duration_compliant_visits' => $inputs->durationCompliantVisits,
                    'visits_missing_timestamps' => $inputs->visitsMissingTimestamps,
                    'detected_opportunities' => $inputs->detectedOpportunities,
                    'required_visit_minutes' => $inputs->requiredVisitMinutes,
                ],
                'weighted_values' => [
                    'task_completion' => $result->taskScore,
                    'visit_completion' => $result->visitScore,
                    'schedule_adherence' => $result->scheduleScore,
                    'work_time_adherence' => $result->workTimeScore,
                    'potential_sales_opportunities' => $result->opportunityScore,
                ],
                'calculated_at' => now(),
            ]);

            activity()->performedOn($score)
                ->withChanges(['attributes' => $score->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('performance.calculated');

            return $score;
        });
    }

    private function gatherInputs(SalesPlan $plan): PerformanceScoreInputs
    {
        $tasks = PlanTask::query()
            ->where('sales_plan_id', $plan->id)
            ->whereNull('source_visit_id')
            ->get();
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

        $detectedOpportunities = SalesOpportunity::query()
            ->where(function (Builder $query) use ($plan): void {
                $query->whereHas('sourceVisit.planTask', fn (Builder $task): Builder => $task->where('sales_plan_id', $plan->id))
                    ->orWhereHas(
                        'transcription.employeeVoiceNote.customerVisit.planTask',
                        fn (Builder $task): Builder => $task->where('sales_plan_id', $plan->id),
                    );
            })
            ->count();

        return new PerformanceScoreInputs(
            totalTasks: $tasks->count(),
            completedTasks: $completedTasks->count(),
            onTimeCompletedTasks: $onTimeCompletedTasks->count(),
            totalVisits: $visits->count(),
            completedVisits: $completedVisits->count(),
            durationCompliantVisits: $durationCompliantVisits->count(),
            visitsMissingTimestamps: $visitsMissingTimestamps->count(),
            detectedOpportunities: $detectedOpportunities,
            requiredVisitMinutes: $plan->requiredVisitMinutes(),
            taskWeight: (float) $plan->task_weight,
            visitWeight: (float) $plan->visit_weight,
            scheduleWeight: (float) $plan->schedule_weight,
            workTimeWeight: (float) $plan->work_time_weight,
            opportunityWeight: (float) $plan->opportunity_weight,
        );
    }

    public static function isTaskOnTime(PlanTask $task): bool
    {
        return $task->completed_at !== null
            && $task->completed_at->toDateString() <= $task->due_at->toDateString();
    }

    private function ratio(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : $numerator / $denominator;
    }

    /** @return array{numerator:int, denominator:int, ratio:float, weight:float, contribution:float} */
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
