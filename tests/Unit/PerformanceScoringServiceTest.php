<?php

declare(strict_types=1);

use App\Enums\PlanTaskStatus;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\Data\PerformanceScoreInputs;
use App\Services\Employees\PerformanceScoringService;

function performanceInputs(array $overrides = []): PerformanceScoreInputs
{
    return new PerformanceScoreInputs(...array_merge([
        'totalTasks' => 10,
        'completedTasks' => 10,
        'onTimeCompletedTasks' => 10,
        'totalVisits' => 10,
        'completedVisits' => 10,
        'durationCompliantVisits' => 10,
        'visitsMissingTimestamps' => 0,
        'unattributedVisitCount' => 0,
        'requiredVisitMinutes' => 30,
        'taskWeight' => 40.0,
        'visitWeight' => 30.0,
        'scheduleWeight' => 20.0,
        'workTimeWeight' => 10.0,
    ], $overrides));
}

it('scores a plan with zero tasks and zero visits as zero across every factor, without dividing by zero', function (): void {
    $service = new PerformanceScoringService(new AuditLogger);

    $result = $service->calculate(performanceInputs([
        'totalTasks' => 0, 'completedTasks' => 0, 'onTimeCompletedTasks' => 0,
        'totalVisits' => 0, 'completedVisits' => 0, 'durationCompliantVisits' => 0,
    ]));

    expect($result->taskScore)->toBe(0.0)
        ->and($result->visitScore)->toBe(0.0)
        ->and($result->scheduleScore)->toBe(0.0)
        ->and($result->workTimeScore)->toBe(0.0)
        ->and($result->totalScore)->toBe(0.0);
});

it('scores full completion across all four factors as the full weight sum', function (): void {
    $service = new PerformanceScoringService(new AuditLogger);

    $result = $service->calculate(performanceInputs());

    expect($result->taskScore)->toBe(40.0)
        ->and($result->visitScore)->toBe(30.0)
        ->and($result->scheduleScore)->toBe(20.0)
        ->and($result->workTimeScore)->toBe(10.0)
        ->and($result->totalScore)->toBe(100.0);
});

it('scores partial completion for each factor independently', function (): void {
    $service = new PerformanceScoringService(new AuditLogger);

    $result = $service->calculate(performanceInputs([
        'completedTasks' => 5, 'onTimeCompletedTasks' => 5,
        'completedVisits' => 5, 'durationCompliantVisits' => 5,
    ]));

    expect($result->taskScore)->toBe(20.0)
        ->and($result->visitScore)->toBe(15.0)
        ->and($result->scheduleScore)->toBe(20.0)
        ->and($result->workTimeScore)->toBe(10.0);
});

it('reproduces the D5 worked example exactly: 8 of 10 tasks on time is 80%, scoring 8.00 at weight 10', function (): void {
    $service = new PerformanceScoringService(new AuditLogger);

    $result = $service->calculate(performanceInputs([
        'completedTasks' => 10,
        'onTimeCompletedTasks' => 8,
        'scheduleWeight' => 10.0,
    ]));

    expect($result->breakdown['schedule_adherence']['ratio'])->toBe(0.8)
        ->and($result->scheduleScore)->toBe(8.0);
});

it('scores a zero-denominator task_completion factor as 0 without redistributing its weight', function (): void {
    // Only totalTasks is zeroed; completedTasks (schedule_adherence's own
    // denominator) is untouched, so schedule_adherence stays unaffected —
    // this is the clean, fully-isolated case since the two factors do not
    // share an underlying count here.
    $service = new PerformanceScoringService(new AuditLogger);

    $result = $service->calculate(performanceInputs(['totalTasks' => 0]));

    expect($result->taskScore)->toBe(0.0)
        ->and($result->scheduleScore)->toBe(20.0)
        ->and($result->totalScore)->toBe(60.0);
});

it('scores a zero-denominator visit_completion factor as 0 without redistributing its weight', function (): void {
    // Only totalVisits is zeroed; completedVisits (work_time_adherence's own
    // denominator) is untouched, so work_time_adherence stays unaffected.
    $service = new PerformanceScoringService(new AuditLogger);

    $result = $service->calculate(performanceInputs(['totalVisits' => 0]));

    expect($result->visitScore)->toBe(0.0)
        ->and($result->workTimeScore)->toBe(10.0)
        ->and($result->totalScore)->toBe(70.0);
});

it('scores a zero-denominator schedule_adherence factor as 0 without redistributing its weight', function (): void {
    // completedTasks is schedule_adherence's denominator AND
    // task_completion's numerator, so zeroing it (no tasks completed yet)
    // correctly zeros both — that is real arithmetic, not redistribution.
    // The guarantee under test is that neither factor's weight leaks into
    // visit_completion or work_time_adherence, which stay at full marks.
    $service = new PerformanceScoringService(new AuditLogger);

    $result = $service->calculate(performanceInputs(['completedTasks' => 0, 'onTimeCompletedTasks' => 0]));

    expect($result->taskScore)->toBe(0.0)
        ->and($result->scheduleScore)->toBe(0.0)
        ->and($result->visitScore)->toBe(30.0)
        ->and($result->workTimeScore)->toBe(10.0)
        ->and($result->totalScore)->toBe(40.0);
});

it('scores a zero-denominator work_time_adherence factor as 0 without redistributing its weight', function (): void {
    // completedVisits is work_time_adherence's denominator AND
    // visit_completion's numerator, so zeroing it (no visits completed yet)
    // correctly zeros both; task_completion and schedule_adherence stay at
    // full marks, proving no weight leaked across.
    $service = new PerformanceScoringService(new AuditLogger);

    $result = $service->calculate(performanceInputs(['completedVisits' => 0, 'durationCompliantVisits' => 0]));

    expect($result->visitScore)->toBe(0.0)
        ->and($result->workTimeScore)->toBe(0.0)
        ->and($result->taskScore)->toBe(40.0)
        ->and($result->scheduleScore)->toBe(20.0)
        ->and($result->totalScore)->toBe(60.0);
});

it('treats completed_at equal to due_at as on time', function (): void {
    $task = new PlanTask;
    $task->status = PlanTaskStatus::Completed;
    $task->due_at = '2026-03-15';
    $task->completed_at = '2026-03-15 23:59:00';

    expect(PerformanceScoringService::isTaskOnTime($task))->toBeTrue();
});

it('treats a completed task finished after its due date as not on time', function (): void {
    $task = new PlanTask;
    $task->status = PlanTaskStatus::Completed;
    $task->due_at = '2026-03-15';
    $task->completed_at = '2026-03-16 00:01:00';

    expect(PerformanceScoringService::isTaskOnTime($task))->toBeFalse();
});

it('never counts an incomplete task as on time', function (): void {
    $task = new PlanTask;
    $task->status = PlanTaskStatus::InProgress;
    $task->due_at = '2026-03-15';
    $task->completed_at = null;

    expect(PerformanceScoringService::isTaskOnTime($task))->toBeFalse();
});

it('resolves the effective required_visit_minutes from the plan, falling back to config, and snapshots it', function (): void {
    config(['employees.default_required_visit_minutes' => 45]);
    $planWithOwnThreshold = new SalesPlan;
    $planWithOwnThreshold->required_visit_minutes = 20;

    $planWithoutOwnThreshold = new SalesPlan;
    $planWithoutOwnThreshold->required_visit_minutes = null;

    expect($planWithOwnThreshold->requiredVisitMinutes())->toBe(20)
        ->and($planWithoutOwnThreshold->requiredVisitMinutes())->toBe(45);

    $service = new PerformanceScoringService(new AuditLogger);
    $result = $service->calculate(performanceInputs(['requiredVisitMinutes' => 20]));

    expect($result->breakdown['work_time_adherence']['required_visit_minutes'])->toBe(20);
});

it('records the unattributed visit count in the breakdown without letting it affect the ratio', function (): void {
    $service = new PerformanceScoringService(new AuditLogger);

    $result = $service->calculate(performanceInputs(['unattributedVisitCount' => 3]));

    expect($result->breakdown['visit_completion']['unattributed_visit_count'])->toBe(3)
        ->and($result->breakdown['work_time_adherence']['unattributed_visit_count'])->toBe(3)
        ->and($result->visitScore)->toBe(30.0);
});

it('records the missing-timestamp visit count separately from the denominator it still counts in', function (): void {
    $service = new PerformanceScoringService(new AuditLogger);

    $result = $service->calculate(performanceInputs([
        'completedVisits' => 10,
        'durationCompliantVisits' => 8,
        'visitsMissingTimestamps' => 2,
    ]));

    expect($result->breakdown['work_time_adherence']['denominator'])->toBe(10)
        ->and($result->breakdown['work_time_adherence']['numerator'])->toBe(8)
        ->and($result->breakdown['work_time_adherence']['missing_timestamp_visit_count'])->toBe(2);
});

it('makes total_score equal performance_percent while keeping task_completion_percent a separate statistic', function (): void {
    $service = new PerformanceScoringService(new AuditLogger);

    $result = $service->calculate(performanceInputs(['completedTasks' => 5, 'onTimeCompletedTasks' => 5]));

    expect($result->totalScore)->toBe($result->taskScore + $result->visitScore + $result->scheduleScore + $result->workTimeScore)
        ->and($result->taskCompletionPercent)->toBe(50.0)
        ->and($result->taskCompletionPercent)->not->toBe($result->totalScore);
});
