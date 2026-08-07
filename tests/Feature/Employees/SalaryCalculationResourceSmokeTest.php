<?php

declare(strict_types=1);

use App\Filament\Resources\Performance\PerformanceResource;
use App\Filament\Resources\SalaryCalculations\SalaryCalculationResource;
use App\Models\BonusSuggestion;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\SalesPlan;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('renders the performance list and view pages without error', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $score = EmployeePerformanceScore::factory()->create();

    $this->actingAs($admin)->get(PerformanceResource::getUrl('index'))->assertOk();
    $this->actingAs($admin)->get(PerformanceResource::getUrl('view', ['record' => $score]))->assertOk();
});

it('renders the salary calculation list and view pages, including the bonus-suggestions relation manager, without error', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $employee->id]);
    $calculation = EmployeeSalaryCalculation::factory()->create([
        'employee_id' => $employee->id,
        'sales_plan_id' => $plan->id,
    ]);
    BonusSuggestion::factory()->create(['employee_id' => $employee->id, 'sales_plan_id' => $plan->id]);

    $this->actingAs($admin)->get(SalaryCalculationResource::getUrl('index'))->assertOk();
    $this->actingAs($admin)->get(SalaryCalculationResource::getUrl('view', ['record' => $calculation]))->assertOk();
});
