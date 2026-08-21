<?php

declare(strict_types=1);

use App\Enums\EmployeeReportType;
use App\Enums\VisitStatus;
use App\Models\BonusSuggestion;
use App\Models\CustomerVisit;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Employees\EmployeeReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns every plan for the PlanCompletion report', function (): void {
    $withTasks = SalesPlan::factory()->withTasks(3)->create();
    $withoutTasks = SalesPlan::factory()->create();

    $ids = app(EmployeeReportService::class)->query(EmployeeReportType::PlanCompletion)->pluck('id');

    expect($ids)->toContain($withTasks->id, $withoutTasks->id);
});

it('returns only overdue tasks for the OverdueTasks report', function (): void {
    $overdue = PlanTask::factory()->overdue()->create();
    $onTrack = PlanTask::factory()->create(['starts_at' => now(), 'due_at' => now()->addDays(10)]);

    $ids = app(EmployeeReportService::class)->query(EmployeeReportType::OverdueTasks)->pluck('id');

    expect($ids)->toContain($overdue->id)
        ->and($ids)->not->toContain($onTrack->id);
});

it('returns only Planned and Missed visits for the UnexecutedVisits report', function (): void {
    $planned = CustomerVisit::factory()->create(['status' => VisitStatus::Planned]);
    $missed = CustomerVisit::factory()->create(['status' => VisitStatus::Missed]);
    $completed = CustomerVisit::factory()->completed()->create();

    $ids = app(EmployeeReportService::class)->query(EmployeeReportType::UnexecutedVisits)->pluck('id');

    expect($ids)->toContain($planned->id, $missed->id)
        ->and($ids)->not->toContain($completed->id);
});

it('returns performance scores filtered by employee for PerformanceByEmployee', function (): void {
    $employee = EmployeeProfile::factory()->create();
    $matching = EmployeePerformanceScore::factory()->create(['employee_id' => $employee->id]);
    $other = EmployeePerformanceScore::factory()->create();

    $ids = app(EmployeeReportService::class)
        ->query(EmployeeReportType::PerformanceByEmployee, ['employee_id' => $employee->id])
        ->pluck('id');

    expect($ids)->toContain($matching->id)
        ->and($ids)->not->toContain($other->id);
});

it('returns performance scores filtered by plan month for PerformanceByMonth', function (): void {
    $planInMonth = SalesPlan::factory()->create(['month' => '2026-03-01']);
    $scoreInMonth = EmployeePerformanceScore::factory()->create(['sales_plan_id' => $planInMonth->id]);
    $scoreOutsideMonth = EmployeePerformanceScore::factory()->create();

    $ids = app(EmployeeReportService::class)
        ->query(EmployeeReportType::PerformanceByMonth, ['month' => '2026-03-01'])
        ->pluck('id');

    expect($ids)->toContain($scoreInMonth->id)
        ->and($ids)->not->toContain($scoreOutsideMonth->id);
});

it('returns salary calculations filtered by employee for SalaryByEmployee', function (): void {
    $employee = EmployeeProfile::factory()->create();
    $matching = EmployeeSalaryCalculation::factory()->create(['employee_id' => $employee->id]);
    $other = EmployeeSalaryCalculation::factory()->create();

    $ids = app(EmployeeReportService::class)
        ->query(EmployeeReportType::SalaryByEmployee, ['employee_id' => $employee->id])
        ->pluck('id');

    expect($ids)->toContain($matching->id)
        ->and($ids)->not->toContain($other->id);
});

it('returns salary calculations filtered by plan month for SalaryByMonth', function (): void {
    $planInMonth = SalesPlan::factory()->create(['month' => '2026-04-01']);
    $salaryInMonth = EmployeeSalaryCalculation::factory()->create(['sales_plan_id' => $planInMonth->id]);
    $salaryOutsideMonth = EmployeeSalaryCalculation::factory()->create();

    $ids = app(EmployeeReportService::class)
        ->query(EmployeeReportType::SalaryByMonth, ['month' => '2026-04-01'])
        ->pluck('id');

    expect($ids)->toContain($salaryInMonth->id)
        ->and($ids)->not->toContain($salaryOutsideMonth->id);
});

it('does not silently pull in unrelated bonus suggestions when reporting on salary', function (): void {
    $employee = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $employee->id]);
    EmployeeSalaryCalculation::factory()->create(['employee_id' => $employee->id, 'sales_plan_id' => $plan->id]);
    BonusSuggestion::factory()->create(['employee_id' => $employee->id, 'sales_plan_id' => $plan->id]);

    $results = app(EmployeeReportService::class)->query(EmployeeReportType::SalaryByEmployee)->get();

    expect($results->first())->toBeInstanceOf(EmployeeSalaryCalculation::class);
});

it('refuses to authorize a report view for an actor without the required permissions', function (): void {
    $actor = User::factory()->create();

    expect(fn () => app(EmployeeReportService::class)->authorizeView($actor, EmployeeReportType::PlanCompletion))
        ->toThrow(DomainException::class);
});

it('filters overdue tasks down to the plan owned by the requested employee', function (): void {
    $employee = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $employee->id]);
    $matching = PlanTask::factory()->overdue()->create(['sales_plan_id' => $plan->id]);
    $otherEmployeesTask = PlanTask::factory()->overdue()->create();

    $ids = app(EmployeeReportService::class)
        ->query(EmployeeReportType::OverdueTasks, ['employee_id' => $employee->id])
        ->pluck('id');

    expect($ids)->toContain($matching->id)
        ->and($ids)->not->toContain($otherEmployeesTask->id);
});

it('filters plan completion by month via a direct column comparison, not a relation', function (): void {
    $planInMonth = SalesPlan::factory()->create(['month' => '2026-03-01']);
    $planOutsideMonth = SalesPlan::factory()->create(['month' => '2026-04-01']);

    $ids = app(EmployeeReportService::class)
        ->query(EmployeeReportType::PlanCompletion, ['month' => '2026-03-01'])
        ->pluck('id');

    expect($ids)->toContain($planInMonth->id)
        ->and($ids)->not->toContain($planOutsideMonth->id);
});
