<?php

declare(strict_types=1);

use App\Filament\Resources\Performance\Pages\ListPerformanceScores;
use App\Filament\Resources\Performance\Pages\ViewPerformanceScore;
use App\Filament\Resources\Performance\PerformanceResource;
use App\Filament\Resources\SalaryCalculations\Pages\ListSalaryCalculations;
use App\Filament\Resources\SalaryCalculations\Pages\ViewSalaryCalculation;
use App\Filament\Resources\SalaryCalculations\SalaryCalculationResource;
use App\Models\BonusSuggestion;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Employees\SalaryCalculationService;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

it('shows "No data" for a performance factor with a zero denominator', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $score = EmployeePerformanceScore::factory()->zeroDenominator()->create();

    Livewire::actingAs($admin)
        ->test(ViewPerformanceScore::class, ['record' => $score->getKey()])
        ->assertSee('No data');
});

it('recalculates a performance score via the table row action', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $plan = SalesPlan::factory()->create();
    $score = EmployeePerformanceScore::factory()->create([
        'sales_plan_id' => $plan->id,
        'employee_id' => $plan->employee_id,
        'calculated_at' => now()->subDay(),
    ]);

    $countBefore = EmployeePerformanceScore::query()->count();
    $originalCalculatedAt = $score->calculated_at;

    Livewire::actingAs($admin)
        ->test(ListPerformanceScores::class)
        ->callTableAction('recalculate', $score);

    $latestScore = EmployeePerformanceScore::query()
        ->where('sales_plan_id', $plan->id)
        ->latest('id')
        ->firstOrFail();

    expect(EmployeePerformanceScore::query()->count())->toBe($countBefore + 1)
        ->and($score->fresh()->calculated_at)->toEqual($originalCalculatedAt)
        ->and($latestScore->id)->not->toBe($score->id)
        ->and($latestScore->employee_id)->toBe($score->employee_id)
        ->and($latestScore->sales_plan_id)->toBe($score->sales_plan_id)
        ->and($latestScore->calculated_at)->not->toBeNull();
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

it('links a superseded salary calculation to the row that replaced it', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $replacement = EmployeeSalaryCalculation::factory()->create();
    $superseded = EmployeeSalaryCalculation::factory()->superseded()->create([
        'superseded_by_id' => $replacement->id,
    ]);

    Livewire::actingAs($admin)
        ->test(ViewSalaryCalculation::class, ['record' => $superseded->getKey()])
        ->assertSee('View replacement calculation');
});

it('confirms a pending salary calculation via the table row action', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $calculation = EmployeeSalaryCalculation::factory()->create();

    Livewire::actingAs($admin)
        ->test(ListSalaryCalculations::class)
        ->callTableAction('confirm', $calculation);

    expect($calculation->fresh()->status->value)->toBe('Confirmed');
});

it('recalculates a salary calculation via the table row action', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->baseSalary()->create(['base_salary' => 5000]);
    $plan = SalesPlan::factory()->withTasks(1)->create(['employee_id' => $employee->id]);
    $calculation = app(SalaryCalculationService::class)->calculate($plan);

    $countBefore = EmployeeSalaryCalculation::query()->count();

    Livewire::actingAs($admin)
        ->test(ListSalaryCalculations::class)
        ->callTableAction('recalculate', $calculation);

    expect(EmployeeSalaryCalculation::query()->count())->toBe($countBefore + 1);
});
