<?php

declare(strict_types=1);

use App\Enums\EmployeeReportType;
use App\Enums\VisitStatus;
use App\Filament\Resources\EmployeeReports\EmployeeReportResource;
use App\Filament\Resources\EmployeeReports\Pages\ManageEmployeeReports;
use App\Filament\Resources\EmployeeReports\Schemas\EmployeeReportExportRequestSchema;
use App\Filament\Resources\EmployeeReports\Tables\EmployeeReportFilters;
use App\Jobs\GenerateEmployeeReportExport;
use App\Models\CustomerVisit;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('denies the index page without report permissions and allows a System Admin', function (): void {
    $unauthorized = User::factory()->admin()->create();

    $this->actingAs($unauthorized)
        ->get(EmployeeReportResource::getUrl())
        ->assertForbidden();

    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $this->actingAs($admin)
        ->get(EmployeeReportResource::getUrl())
        ->assertOk();
});

it('renders every report tab with its own columns against real underlying records', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $emptyPlan = SalesPlan::factory()->create();
    $mixedPlan = SalesPlan::factory()->create();
    PlanTask::factory()->for($mixedPlan, 'salesPlan')->completed()->create();
    PlanTask::factory()->for($mixedPlan, 'salesPlan')->create();

    $overdueTask = PlanTask::factory()->overdue()->create();
    $missedVisit = CustomerVisit::factory()->create(['status' => VisitStatus::Missed]);
    $performanceScore = EmployeePerformanceScore::factory()->create();
    $salaryCalculation = EmployeeSalaryCalculation::factory()->create();

    $component = Livewire::actingAs($admin)->test(ManageEmployeeReports::class)->assertOk();

    $component
        ->set('activeTab', EmployeeReportType::PlanCompletion->value)
        ->assertCanSeeTableRecords([$emptyPlan, $mixedPlan])
        ->assertTableColumnStateSet('completion', 0.0, $emptyPlan)
        ->assertTableColumnStateSet('completion', 50.0, $mixedPlan);

    $component
        ->set('activeTab', EmployeeReportType::OverdueTasks->value)
        ->assertCanSeeTableRecords([$overdueTask]);

    $component
        ->set('activeTab', EmployeeReportType::UnexecutedVisits->value)
        ->assertCanSeeTableRecords([$missedVisit]);

    $component
        ->set('activeTab', EmployeeReportType::PerformanceByEmployee->value)
        ->assertCanSeeTableRecords([$performanceScore]);

    $component
        ->set('activeTab', EmployeeReportType::SalaryByEmployee->value)
        ->assertCanSeeTableRecords([$salaryCalculation]);
});

it('dispatches the export job when the export action is submitted', function (): void {
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');
    EmployeeProfile::factory()->create();

    Livewire::actingAs($admin)
        ->test(ManageEmployeeReports::class)
        ->assertActionVisible('export')
        ->callAction('export', data: [])
        ->assertHasNoActionErrors();

    Bus::assertDispatched(GenerateEmployeeReportExport::class);
});

it('covers report resource metadata fallbacks and defensive formatters', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $component = Livewire::actingAs($admin)->test(ManageEmployeeReports::class);
    $page = $component->instance();
    $reportType = new ReflectionMethod($page, 'reportType');
    $reportFilters = new ReflectionMethod($page, 'reportFilters');

    $page->tableFilters = [
        'ignored' => 'not-an-array',
        'direct' => ['value' => 'active'],
        'grouped' => ['employee_id' => 10, 0 => 'discarded'],
    ];

    expect(EmployeeReportResource::getNavigationLabel())->toBe(__('admin.resources.employee_reports'))
        ->and(EmployeeReportResource::form(Schema::make())->getComponents())->toBe([])
        ->and(EmployeeReportResource::canViewAny())->toBeTrue()
        ->and(EmployeeReportResource::canCreate())->toBeFalse()
        ->and($reportFilters->invoke($page))->toBe([
            'direct' => 'active',
            'employee_id' => 10,
        ]);

    $page->activeTab = null;
    expect($reportType->invoke($page))->toBe(EmployeeReportType::PlanCompletion);

    auth()->logout();

    expect($reportType->invoke($page))->toBe(EmployeeReportType::PlanCompletion);
});

it('labels employee options using the profile user name', function (): void {
    $employee = EmployeeProfile::factory()->create();

    $filterLabel = new ReflectionMethod(EmployeeReportFilters::class, 'employeeLabel');
    $schemaLabel = new ReflectionMethod(EmployeeReportExportRequestSchema::class, 'employeeLabel');

    expect($filterLabel->invoke(null, $employee))->toBe($employee->user->name)
        ->and($schemaLabel->invoke(null, $employee))->toBe($employee->user->name);
});
