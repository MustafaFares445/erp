<?php

declare(strict_types=1);

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\SalaryCalculations\Pages\ListSalaryCalculations;
use App\Filament\Resources\SalaryCalculations\SalaryCalculationResource;
use App\Filament\Resources\SalesOpportunities\Pages\ListSalesOpportunities;
use App\Filament\Resources\SalesOpportunities\SalesOpportunityResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\SalesOpportunity;
use App\Models\SalesPlan;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('authorizes page-open identically to the policy for Visits, opportunity drafts, and salary calculations', function (): void {
    $payrollOfficer = User::factory()->admin()->create();
    $payrollOfficer->assignRole('Payroll Officer');

    $employeeManager = User::factory()->admin()->create();
    $employeeManager->assignRole('Employee Manager');

    // Payroll Officer: denied every field/AI surface (no visit/opportunity view).
    $this->actingAs($payrollOfficer)->get(VisitResource::getUrl('index'))->assertForbidden();
    $this->actingAs($payrollOfficer)->get(SalesOpportunityResource::getUrl('index'))->assertForbidden();
    // ...but granted the compensation surface.
    $this->actingAs($payrollOfficer)->get(SalaryCalculationResource::getUrl('index'))->assertOk();

    // Employee Manager: granted every field/AI surface (view-only for opportunity)...
    $this->actingAs($employeeManager)->get(VisitResource::getUrl('index'))->assertOk();
    $this->actingAs($employeeManager)->get(SalesOpportunityResource::getUrl('index'))->assertOk();
    // ...but denied the compensation surface entirely.
    $this->actingAs($employeeManager)->get(SalaryCalculationResource::getUrl('index'))->assertForbidden();
});

it('hides the salary-confirm action from a Reviewer but keeps it reachable for a Payroll Officer', function (): void {
    $employee = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $employee->id]);
    $calculation = EmployeeSalaryCalculation::factory()->create([
        'employee_id' => $employee->id,
        'sales_plan_id' => $plan->id,
    ]);

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');
    Livewire::actingAs($reviewer)
        ->test(ListSalaryCalculations::class)
        ->assertTableActionHidden('confirm', $calculation);

    $payrollOfficer = User::factory()->admin()->create();
    $payrollOfficer->assignRole('Payroll Officer');
    Livewire::actingAs($payrollOfficer)
        ->test(ListSalaryCalculations::class)
        ->assertTableActionVisible('confirm', $calculation);
});

it('hides the opportunity-draft approve/reject actions from a Reviewer', function (): void {
    $draft = SalesOpportunity::factory()->create();

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    Livewire::actingAs($reviewer)
        ->test(ListSalesOpportunities::class)
        ->assertTableActionHidden('approve', $draft)
        ->assertTableActionHidden('reject', $draft);
});

it('applies the same bulk-action permission as the single action to a Payroll Officer on Employees', function (): void {
    $payrollOfficer = User::factory()->admin()->create();
    $payrollOfficer->assignRole('Payroll Officer');
    EmployeeProfile::factory()->count(2)->create();

    Livewire::actingAs($payrollOfficer)
        ->test(ListEmployees::class)
        ->assertTableBulkActionHidden('archive');

    $this->actingAs($payrollOfficer)->get(EmployeeResource::getUrl('index'))->assertOk();
});
