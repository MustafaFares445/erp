<?php

declare(strict_types=1);

use App\Enums\SalaryCalculationMode;
use App\Filament\Resources\Performance\Pages\ListPerformanceScores;
use App\Filament\Resources\Performance\Pages\ViewPerformanceScore;
use App\Filament\Resources\Performance\PerformanceResource;
use App\Filament\Resources\SalaryCalculations\Pages\ListSalaryCalculations;
use App\Filament\Resources\SalaryCalculations\Pages\ViewSalaryCalculation;
use App\Filament\Resources\SalaryCalculations\RelationManagers\BonusSuggestionsRelationManager;
use App\Filament\Resources\SalaryCalculations\SalaryCalculationResource;
use App\Models\BonusSuggestion;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Employees\SalaryCalculationService;
use Database\Seeders\EmployeePermissionSeeder;
use Filament\Tables\Columns\Column;
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

it('renders localized employee performance and salary labels in Arabic', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $employee->id]);
    EmployeePerformanceScore::factory()->create([
        'employee_id' => $employee->id,
        'sales_plan_id' => $plan->id,
    ]);
    EmployeeSalaryCalculation::factory()->create([
        'employee_id' => $employee->id,
        'sales_plan_id' => $plan->id,
    ]);

    app()->setLocale('ar');

    Livewire::actingAs($admin)
        ->test(ListPerformanceScores::class)
        ->assertSee('شهر الخطة')
        ->assertSee('بداية فترة الأداء');

    $score = EmployeePerformanceScore::query()->firstOrFail();

    Livewire::actingAs($admin)
        ->test(ViewPerformanceScore::class, ['record' => $score->getKey()])
        ->assertSee('بداية فترة الأداء');

    Livewire::actingAs($admin)
        ->test(ListSalaryCalculations::class)
        ->assertSee('بانتظار التأكيد');

    $calculation = EmployeeSalaryCalculation::query()->firstOrFail();

    Livewire::actingAs($admin)
        ->test(ViewSalaryCalculation::class, ['record' => $calculation->getKey()])
        ->assertSee('ملخص الاحتساب');
});

it('shows localized no-data text for a performance factor with a zero denominator', function (string $locale, string $label): void {
    $admin = User::factory()->admin()->create(['locale' => $locale]);
    $admin->assignRole('System Admin');

    $score = EmployeePerformanceScore::factory()->zeroDenominator()->create();

    app()->setLocale($locale);

    Livewire::actingAs($admin)
        ->test(ViewPerformanceScore::class, ['record' => $score->getKey()])
        ->assertSee($label);
})->with([
    'English' => ['en', 'No data'],
    'Arabic' => ['ar', 'لا توجد بيانات'],
]);

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

it('renders the stored salary mode and formula as localized human labels without changing the snapshot', function (string $locale, string $modeLabel, string $payableBaseLabel, string $performanceLabel, string $approvedBonusLabel): void {
    $admin = User::factory()->admin()->create(['locale' => $locale]);
    $admin->assignRole('System Admin');

    $calculation = EmployeeSalaryCalculation::factory()->create([
        'salary_calculation_mode_snapshot' => SalaryCalculationMode::PerformanceOnly->value,
        'calculation_explanation' => [
            'formula' => 'payable_base × performance_percent / 100 + approved_bonus_amount',
        ],
    ]);

    app()->setLocale($locale);

    $this->actingAs($admin)
        ->get(SalaryCalculationResource::getUrl('view', ['record' => $calculation]))
        ->assertOk()
        ->assertSee($modeLabel)
        ->assertSee($payableBaseLabel)
        ->assertSee($performanceLabel)
        ->assertSee($approvedBonusLabel)
        ->assertDontSeeText('performance_only')
        ->assertDontSeeText('payable_base')
        ->assertDontSeeText('performance_percent')
        ->assertDontSeeText('approved_bonus_amount');

    expect($calculation->fresh()->salary_calculation_mode_snapshot)->toBe(SalaryCalculationMode::PerformanceOnly->value)
        ->and($calculation->fresh()->calculation_explanation['formula'])->toBe('payable_base × performance_percent / 100 + approved_bonus_amount');
})->with([
    'English' => ['en', 'Performance Only', 'Payable base', 'Performance', 'Approved bonus'],
    'Arabic' => ['ar', 'الأداء فقط', 'أساس الذمم الدائنة', 'الأداء', 'المكافأة المعتمدة'],
]);

it('keeps the core salary columns visible while leaving secondary breakdown columns available but hidden by default', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');
    EmployeeSalaryCalculation::factory()->create();

    Livewire::actingAs($admin)
        ->test(ListSalaryCalculations::class)
        ->assertTableColumnVisible('employee.user.name')
        ->assertTableColumnVisible('salesPlan.name')
        ->assertTableColumnVisible('salesPlan.month')
        ->assertTableColumnVisible('performance_percent')
        ->assertTableColumnVisible('final_salary')
        ->assertTableColumnVisible('status')
        ->assertTableColumnExists('payable_base', static fn (Column $column): bool => $column->isToggleable() && $column->isToggledHidden())
        ->assertTableColumnExists('bonus_amount', static fn (Column $column): bool => $column->isToggleable() && $column->isToggledHidden());
});

it('uses a localized bonus suggestion label for the relation manager create action', function (string $locale, string $label): void {
    $admin = User::factory()->admin()->create(['locale' => $locale]);
    $admin->assignRole('System Admin');

    $calculation = EmployeeSalaryCalculation::factory()->create();

    app()->setLocale($locale);

    Livewire::actingAs($admin)
        ->test(BonusSuggestionsRelationManager::class, [
            'ownerRecord' => $calculation,
            'pageClass' => ViewSalaryCalculation::class,
        ])
        ->assertTableActionHasLabel('create', $label);
})->with([
    'English' => ['en', 'New bonus suggestion'],
    'Arabic' => ['ar', 'إضافة مقترح مكافأة'],
]);

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

it('shows the performance snapshot, review figures and history in human-readable form', function (): void {
    // Release QA 2026-10-10: salary view showed a bare snapshot id, raw review numbers and an empty History card.
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $score = EmployeePerformanceScore::factory()->create(['total_score' => 43.5]);
    $calculation = EmployeeSalaryCalculation::factory()->create([
        'employee_id' => $score->employee_id,
        'sales_plan_id' => $score->sales_plan_id,
        'performance_score_id' => $score->id,
        'performance_percent' => 43.5,
        'bonus_amount' => 0,
        'calculation_explanation' => [
            'performance_percent' => 43.5,
            'approved_bonus_amount' => 1250,
        ],
    ]);

    Livewire::actingAs($admin)
        ->test(ViewSalaryCalculation::class, ['record' => $calculation->getKey()])
        ->assertSeeText('Total score 43.50%')
        ->assertSeeHtml(PerformanceResource::getUrl('view', ['record' => $score]))
        ->assertDontSeeText('43.5%')
        ->assertSeeText('1,250.00')
        ->assertSchemaComponentHidden('history', 'infolist');
});
