<?php

declare(strict_types=1);

use App\Enums\BonusSuggestionStatus;
use App\Models\AuditLog;
use App\Models\BonusSuggestion;
use App\Models\EmployeeProfile;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Employees\BonusApprovalService;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use App\Services\Employees\SalaryCalculationService;
use App\Services\Employees\SalaryRecalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses to calculate salary for a plan that has somehow lost its employee', function (): void {
    $plan = SalesPlan::factory()->create();
    $plan->setRelation('employee', null);

    expect(fn () => app(SalaryCalculationService::class)->calculate($plan))
        ->toThrow(DomainException::class);
});

it('rejects a bonus decision on a suggestion that has already been decided', function (): void {
    $decided = BonusSuggestion::factory()->create(['status' => BonusSuggestionStatus::Approved]);

    expect(fn () => app(BonusApprovalService::class)->reject($decided))
        ->toThrow(InvalidStatusTransition::class);
});

it('audits salary calculate, confirm, and supersede', function (): void {
    $employee = EmployeeProfile::factory()->baseSalary()->create(['base_salary' => 5000]);
    $plan = SalesPlan::factory()->withTasks(1)->create(['employee_id' => $employee->id]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $calculation = app(SalaryCalculationService::class)->calculate($plan);
    expect(AuditLog::query()->where('description', 'salary.calculated')->where('subject_id', $calculation->id)->exists())->toBeTrue();

    app(SalaryRecalculationService::class)->confirm($calculation);
    expect(AuditLog::query()->where('description', 'salary.confirmed')->where('subject_id', $calculation->id)->exists())->toBeTrue();

    $recalculated = app(SalaryRecalculationService::class)->recalculate($plan);
    expect(AuditLog::query()->where('description', 'salary.superseded')->where('subject_id', $calculation->id)->exists())->toBeTrue()
        ->and($recalculated->id)->not->toBe($calculation->id);
});

it('audits bonus suggestion approval and rejection', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $approved = BonusSuggestion::factory()->create();
    $rejected = BonusSuggestion::factory()->create();

    app(BonusApprovalService::class)->approve($approved, 'Strong quarter');
    app(BonusApprovalService::class)->reject($rejected, 'Not warranted');

    expect(AuditLog::query()->where('description', 'bonus.approved')->where('subject_id', $approved->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('description', 'bonus.rejected')->where('subject_id', $rejected->id)->exists())->toBeTrue();
});
