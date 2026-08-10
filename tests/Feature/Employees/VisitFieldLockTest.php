<?php

declare(strict_types=1);

use App\Filament\Resources\Visits\VisitResource;
use App\Models\AuditLog;
use App\Models\CustomerVisit;
use App\Models\User;
use App\Policies\CustomerVisitPolicy;
use App\Services\Employees\VisitReviewService;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('denies editing a visit to every role except a field-edit holder', function (): void {
    $employeeManager = User::factory()->admin()->create();
    $employeeManager->assignRole('Employee Manager');

    $payrollOfficer = User::factory()->admin()->create();
    $payrollOfficer->assignRole('Payroll Officer');

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $systemAdmin = User::factory()->admin()->create();
    $systemAdmin->assignRole('System Admin');

    $policy = app(CustomerVisitPolicy::class);

    expect($policy->update($employeeManager))->toBeFalse()
        ->and($policy->update($payrollOfficer))->toBeFalse()
        ->and($policy->update($reviewer))->toBeFalse()
        ->and($policy->update($systemAdmin))->toBeTrue();
});

it('lets an admin field-edit a locked visit and audits the change', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $visit = CustomerVisit::factory()->create(['outcome' => 'Original outcome']);

    app(VisitReviewService::class)->updateFieldRecordedVisit($visit, ['outcome' => 'Corrected outcome']);

    expect($visit->fresh()->outcome)->toBe('Corrected outcome')
        ->and(
            AuditLog::query()->where('action', 'visit.field_edited')->where('entity_id', $visit->id)->exists()
        )->toBeTrue();
});

it('denies the visit edit page to an Employee Manager, even reached directly by URL', function (): void {
    $employeeManager = User::factory()->admin()->create();
    $employeeManager->assignRole('Employee Manager');

    $visit = CustomerVisit::factory()->create();

    $this->actingAs($employeeManager)->get(VisitResource::getUrl('edit', ['record' => $visit]))->assertForbidden();

    $systemAdmin = User::factory()->admin()->create();
    $systemAdmin->assignRole('System Admin');

    $this->actingAs($systemAdmin)->get(VisitResource::getUrl('edit', ['record' => $visit]))->assertOk();
});

it('keeps the review-note action available on a locked visit', function (): void {
    $reviewer = User::factory()->admin()->create();
    $this->actingAs($reviewer);
    $visit = CustomerVisit::factory()->create();

    app(VisitReviewService::class)->updateReviewNote($visit, 'Looks good, verified in person.');

    expect($visit->fresh()->review_note)->toBe('Looks good, verified in person.')
        ->and($visit->fresh()->reviewed_by)->toBe($reviewer->id);
});
