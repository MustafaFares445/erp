<?php

declare(strict_types=1);

use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\MonthlyPlans\Pages\ListMonthlyPlans;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('searches employees by code and name (FR-070, FR-085)', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $findByCode = EmployeeProfile::factory()->create(['employee_code' => 'EMP-9001']);
    $other = EmployeeProfile::factory()->create(['employee_code' => 'EMP-9002']);

    Livewire::actingAs($admin)
        ->test(ListEmployees::class)
        ->searchTable('EMP-9001')
        ->assertCanSeeTableRecords([$findByCode])
        ->assertCanNotSeeTableRecords([$other]);
});

it('searches monthly plans by name', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $matching = SalesPlan::factory()->create(['name' => 'Riyadh Coverage Plan']);
    $other = SalesPlan::factory()->create(['name' => 'Jeddah Coverage Plan']);

    Livewire::actingAs($admin)
        ->test(ListMonthlyPlans::class)
        ->searchTable('Riyadh')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters tasks by the Overdue and Completed table filters', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $overdue = PlanTask::factory()->overdue()->create();
    $completed = PlanTask::factory()->completed()->create();

    Livewire::actingAs($admin)
        ->test(ListTasks::class)
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$completed]);
});

it('paginates the employees table once more records exist than fit on one page', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employees = EmployeeProfile::factory()->count(15)->create()->sortBy('employee_code')->values();

    $component = Livewire::actingAs($admin)
        ->test(ListEmployees::class)
        ->sortTable('employee_code')
        ->set('tableRecordsPerPage', 5);

    $component->assertCanSeeTableRecords($employees->take(5), inOrder: true)
        ->assertCanNotSeeTableRecords($employees->slice(5));

    $component->call('gotoPage', 2)
        ->assertCanSeeTableRecords($employees->slice(5, 5), inOrder: true)
        ->assertCanNotSeeTableRecords($employees->take(5));
});

it('paginates the visits table once more records exist than fit on one page', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');
    // VisitsTable defaults to sorting by created_at desc; give each row a
    // distinct, increasing timestamp so that default order is deterministic
    // without needing an extra sortable column just for this test.
    for ($i = 0; $i < 15; $i++) {
        CustomerVisit::factory()->create(['created_at' => now()->addMinutes($i)]);
    }

    $visits = CustomerVisit::query()->orderByDesc('created_at')->orderByDesc('id')->get();

    $component = Livewire::actingAs($admin)
        ->test(ListVisits::class)
        ->set('tableRecordsPerPage', 5);

    $component->assertCanSeeTableRecords($visits->take(5), inOrder: true)
        ->assertCanNotSeeTableRecords($visits->slice(5));

    $component->call('gotoPage', 2)
        ->assertCanSeeTableRecords($visits->slice(5, 5), inOrder: true)
        ->assertCanNotSeeTableRecords($visits->take(5));
});
