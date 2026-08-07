<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\AiKeywordRulePolicy;
use App\Policies\BonusSuggestionPolicy;
use App\Policies\CustomerVisitPolicy;
use App\Policies\EmployeePerformanceScorePolicy;
use App\Policies\EmployeeProfilePolicy;
use App\Policies\EmployeeSalaryCalculationPolicy;
use App\Policies\EmployeeVoiceNotePolicy;
use App\Policies\PlanTaskPolicy;
use App\Policies\SalesOpportunityDraftPolicy;
use App\Policies\SalesPlanPolicy;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('grants System Admin full access across all ten dashboard domains', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    expect(app(EmployeeProfilePolicy::class)->viewAny($admin))->toBeTrue()
        ->and(app(EmployeeProfilePolicy::class)->create($admin))->toBeTrue()
        ->and(app(SalesPlanPolicy::class)->viewAny($admin))->toBeTrue()
        ->and(app(SalesPlanPolicy::class)->create($admin))->toBeTrue()
        ->and(app(PlanTaskPolicy::class)->viewAny($admin))->toBeTrue()
        ->and(app(PlanTaskPolicy::class)->create($admin))->toBeTrue()
        ->and(app(CustomerVisitPolicy::class)->viewAny($admin))->toBeTrue()
        ->and(app(CustomerVisitPolicy::class)->review($admin))->toBeTrue()
        ->and(app(CustomerVisitPolicy::class)->update($admin))->toBeTrue()
        ->and(app(EmployeeVoiceNotePolicy::class)->viewAny($admin))->toBeTrue()
        ->and(app(EmployeeVoiceNotePolicy::class)->play($admin))->toBeTrue()
        ->and(app(EmployeeVoiceNotePolicy::class)->delete($admin))->toBeTrue()
        ->and(app(AiKeywordRulePolicy::class)->viewAny($admin))->toBeTrue()
        ->and(app(AiKeywordRulePolicy::class)->create($admin))->toBeTrue()
        ->and(app(SalesOpportunityDraftPolicy::class)->viewAny($admin))->toBeTrue()
        ->and(app(SalesOpportunityDraftPolicy::class)->review($admin))->toBeTrue()
        ->and(app(EmployeePerformanceScorePolicy::class)->viewAny($admin))->toBeTrue()
        ->and(app(EmployeePerformanceScorePolicy::class)->recalculate($admin))->toBeTrue()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->viewAny($admin))->toBeTrue()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->create($admin))->toBeTrue()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->confirm($admin))->toBeTrue()
        ->and(app(BonusSuggestionPolicy::class)->viewAny($admin))->toBeTrue()
        ->and(app(BonusSuggestionPolicy::class)->approve($admin))->toBeTrue();
});

it('exercises every CRUD-shaped ability on the performance, salary, and voice-note policies for System Admin', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    expect(app(EmployeePerformanceScorePolicy::class)->create($admin))->toBeTrue()
        ->and(app(EmployeePerformanceScorePolicy::class)->update($admin))->toBeTrue()
        ->and(app(EmployeePerformanceScorePolicy::class)->delete($admin))->toBeTrue()
        ->and(app(EmployeePerformanceScorePolicy::class)->deleteAny($admin))->toBeTrue()
        ->and(app(EmployeePerformanceScorePolicy::class)->restore($admin))->toBeTrue()
        ->and(app(EmployeePerformanceScorePolicy::class)->restoreAny($admin))->toBeTrue()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->update($admin))->toBeTrue()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->delete($admin))->toBeTrue()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->deleteAny($admin))->toBeTrue()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->restore($admin))->toBeTrue()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->restoreAny($admin))->toBeTrue()
        ->and(app(EmployeeVoiceNotePolicy::class)->create($admin))->toBeTrue()
        ->and(app(EmployeeVoiceNotePolicy::class)->update($admin))->toBeTrue()
        ->and(app(EmployeeVoiceNotePolicy::class)->deleteAny($admin))->toBeTrue()
        ->and(app(EmployeeVoiceNotePolicy::class)->restore($admin))->toBeTrue()
        ->and(app(EmployeeVoiceNotePolicy::class)->restoreAny($admin))->toBeTrue();
});

it('denies every CRUD-shaped ability on the performance, salary, and voice-note policies for a non-admin role', function (): void {
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    expect(app(EmployeePerformanceScorePolicy::class)->create($reviewer))->toBeFalse()
        ->and(app(EmployeePerformanceScorePolicy::class)->update($reviewer))->toBeFalse()
        ->and(app(EmployeePerformanceScorePolicy::class)->delete($reviewer))->toBeFalse()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->delete($reviewer))->toBeFalse()
        ->and(app(EmployeeVoiceNotePolicy::class)->create($reviewer))->toBeFalse()
        ->and(app(EmployeePerformanceScorePolicy::class)->deleteAny($reviewer))->toBeFalse()
        ->and(app(EmployeePerformanceScorePolicy::class)->restore($reviewer))->toBeFalse()
        ->and(app(EmployeePerformanceScorePolicy::class)->restoreAny($reviewer))->toBeFalse()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->update($reviewer))->toBeFalse()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->deleteAny($reviewer))->toBeFalse()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->restore($reviewer))->toBeFalse()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->restoreAny($reviewer))->toBeFalse()
        ->and(app(EmployeeVoiceNotePolicy::class)->update($reviewer))->toBeFalse()
        ->and(app(EmployeeVoiceNotePolicy::class)->deleteAny($reviewer))->toBeFalse()
        ->and(app(EmployeeVoiceNotePolicy::class)->restore($reviewer))->toBeFalse()
        ->and(app(EmployeeVoiceNotePolicy::class)->restoreAny($reviewer))->toBeFalse();
});

it('grants Employee Manager exactly its documented access and denies every salary/bonus action', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Employee Manager');

    expect(app(EmployeeProfilePolicy::class)->viewAny($manager))->toBeTrue()
        ->and(app(EmployeeProfilePolicy::class)->create($manager))->toBeTrue()
        ->and(app(SalesPlanPolicy::class)->viewAny($manager))->toBeTrue()
        ->and(app(SalesPlanPolicy::class)->create($manager))->toBeTrue()
        ->and(app(PlanTaskPolicy::class)->viewAny($manager))->toBeTrue()
        ->and(app(PlanTaskPolicy::class)->create($manager))->toBeTrue()
        ->and(app(CustomerVisitPolicy::class)->viewAny($manager))->toBeTrue()
        ->and(app(CustomerVisitPolicy::class)->review($manager))->toBeTrue()
        ->and(app(CustomerVisitPolicy::class)->update($manager))->toBeFalse()
        ->and(app(EmployeeVoiceNotePolicy::class)->viewAny($manager))->toBeTrue()
        ->and(app(EmployeeVoiceNotePolicy::class)->play($manager))->toBeTrue()
        ->and(app(EmployeeVoiceNotePolicy::class)->delete($manager))->toBeFalse()
        ->and(app(AiKeywordRulePolicy::class)->viewAny($manager))->toBeTrue()
        ->and(app(AiKeywordRulePolicy::class)->create($manager))->toBeFalse()
        ->and(app(SalesOpportunityDraftPolicy::class)->viewAny($manager))->toBeTrue()
        ->and(app(SalesOpportunityDraftPolicy::class)->review($manager))->toBeFalse()
        ->and(app(EmployeePerformanceScorePolicy::class)->viewAny($manager))->toBeTrue()
        ->and(app(EmployeePerformanceScorePolicy::class)->recalculate($manager))->toBeFalse()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->viewAny($manager))->toBeFalse()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->create($manager))->toBeFalse()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->confirm($manager))->toBeFalse()
        ->and(app(BonusSuggestionPolicy::class)->viewAny($manager))->toBeFalse()
        ->and(app(BonusSuggestionPolicy::class)->approve($manager))->toBeFalse();
});

it('grants Payroll Officer exactly its documented access and denies employee/plan/task management', function (): void {
    $payroll = User::factory()->admin()->create();
    $payroll->assignRole('Payroll Officer');

    expect(app(EmployeeProfilePolicy::class)->viewAny($payroll))->toBeTrue()
        ->and(app(EmployeeProfilePolicy::class)->create($payroll))->toBeFalse()
        ->and(app(SalesPlanPolicy::class)->viewAny($payroll))->toBeTrue()
        ->and(app(SalesPlanPolicy::class)->create($payroll))->toBeFalse()
        ->and(app(PlanTaskPolicy::class)->viewAny($payroll))->toBeFalse()
        ->and(app(PlanTaskPolicy::class)->create($payroll))->toBeFalse()
        ->and(app(CustomerVisitPolicy::class)->viewAny($payroll))->toBeFalse()
        ->and(app(CustomerVisitPolicy::class)->review($payroll))->toBeFalse()
        ->and(app(EmployeeVoiceNotePolicy::class)->viewAny($payroll))->toBeFalse()
        ->and(app(EmployeeVoiceNotePolicy::class)->play($payroll))->toBeFalse()
        ->and(app(AiKeywordRulePolicy::class)->viewAny($payroll))->toBeFalse()
        ->and(app(SalesOpportunityDraftPolicy::class)->viewAny($payroll))->toBeFalse()
        ->and(app(EmployeePerformanceScorePolicy::class)->viewAny($payroll))->toBeTrue()
        ->and(app(EmployeePerformanceScorePolicy::class)->recalculate($payroll))->toBeTrue()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->viewAny($payroll))->toBeTrue()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->create($payroll))->toBeTrue()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->confirm($payroll))->toBeTrue()
        ->and(app(BonusSuggestionPolicy::class)->viewAny($payroll))->toBeTrue()
        ->and(app(BonusSuggestionPolicy::class)->approve($payroll))->toBeTrue();
});

it('keeps Reviewer read-only across every one of the ten domains', function (): void {
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    expect(app(EmployeeProfilePolicy::class)->viewAny($reviewer))->toBeTrue()
        ->and(app(EmployeeProfilePolicy::class)->create($reviewer))->toBeFalse()
        ->and(app(SalesPlanPolicy::class)->viewAny($reviewer))->toBeTrue()
        ->and(app(SalesPlanPolicy::class)->create($reviewer))->toBeFalse()
        ->and(app(PlanTaskPolicy::class)->viewAny($reviewer))->toBeTrue()
        ->and(app(PlanTaskPolicy::class)->create($reviewer))->toBeFalse()
        ->and(app(CustomerVisitPolicy::class)->viewAny($reviewer))->toBeTrue()
        ->and(app(CustomerVisitPolicy::class)->review($reviewer))->toBeFalse()
        ->and(app(CustomerVisitPolicy::class)->update($reviewer))->toBeFalse()
        ->and(app(EmployeeVoiceNotePolicy::class)->viewAny($reviewer))->toBeTrue()
        ->and(app(EmployeeVoiceNotePolicy::class)->play($reviewer))->toBeFalse()
        ->and(app(EmployeeVoiceNotePolicy::class)->delete($reviewer))->toBeFalse()
        ->and(app(AiKeywordRulePolicy::class)->viewAny($reviewer))->toBeTrue()
        ->and(app(AiKeywordRulePolicy::class)->create($reviewer))->toBeFalse()
        ->and(app(SalesOpportunityDraftPolicy::class)->viewAny($reviewer))->toBeTrue()
        ->and(app(SalesOpportunityDraftPolicy::class)->review($reviewer))->toBeFalse()
        ->and(app(EmployeePerformanceScorePolicy::class)->viewAny($reviewer))->toBeTrue()
        ->and(app(EmployeePerformanceScorePolicy::class)->recalculate($reviewer))->toBeFalse()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->viewAny($reviewer))->toBeTrue()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->create($reviewer))->toBeFalse()
        ->and(app(EmployeeSalaryCalculationPolicy::class)->confirm($reviewer))->toBeFalse()
        ->and(app(BonusSuggestionPolicy::class)->viewAny($reviewer))->toBeTrue()
        ->and(app(BonusSuggestionPolicy::class)->approve($reviewer))->toBeFalse();
});
