<?php

declare(strict_types=1);

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Policies\EmployeeProfilePolicy;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('enforces the employee profile policy matrix for the four fixed roles', function (): void {
    $systemAdmin = User::factory()->admin()->create();
    $systemAdmin->assignRole('System Admin');

    $employeeManager = User::factory()->admin()->create();
    $employeeManager->assignRole('Employee Manager');

    $payrollOfficer = User::factory()->admin()->create();
    $payrollOfficer->assignRole('Payroll Officer');

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $policy = app(EmployeeProfilePolicy::class);

    expect($policy->viewAny($systemAdmin))->toBeTrue()
        ->and($policy->create($systemAdmin))->toBeTrue()
        ->and($policy->restore($systemAdmin))->toBeTrue()
        ->and($policy->viewAny($employeeManager))->toBeTrue()
        ->and($policy->create($employeeManager))->toBeTrue()
        ->and($policy->delete($employeeManager))->toBeTrue()
        ->and($policy->restore($employeeManager))->toBeFalse()
        ->and($policy->viewAny($payrollOfficer))->toBeTrue()
        ->and($policy->create($payrollOfficer))->toBeFalse()
        ->and($policy->update($payrollOfficer))->toBeFalse()
        ->and($policy->viewAny($reviewer))->toBeTrue()
        ->and($policy->create($reviewer))->toBeFalse()
        ->and($policy->delete($reviewer))->toBeFalse();
});

it('denies the employee list page to a user without employees.employee.view', function (): void {
    $payrollOfficer = User::factory()->admin()->create();
    $payrollOfficer->assignRole('Payroll Officer');

    $this->actingAs($payrollOfficer)->get(EmployeeResource::getUrl('index'))->assertOk();

    $employeeChannelUser = User::factory()->employee()->create();

    $this->actingAs($employeeChannelUser)->get(EmployeeResource::getUrl('index'))->assertForbidden();
});

it('denies the create page to a Payroll Officer, even reached directly by URL', function (): void {
    $payrollOfficer = User::factory()->admin()->create();
    $payrollOfficer->assignRole('Payroll Officer');

    $this->actingAs($payrollOfficer)->get(EmployeeResource::getUrl('create'))->assertForbidden();
});

it('hides the bulk delete action from a Reviewer the same way it hides the single action', function (): void {
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');
    EmployeeProfile::factory()->count(2)->create();

    Livewire::actingAs($reviewer)
        ->test(ListEmployees::class)
        ->assertTableBulkActionHidden('archive');
});
