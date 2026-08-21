<?php

declare(strict_types=1);

use App\Filament\Resources\MonthlyPlans\Pages\ViewMonthlyPlan;
use App\Filament\Resources\Performance\Schemas\PerformanceInfolist;
use App\Models\EmployeePerformanceScore;
use App\Models\SalesPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the stage bar and record information for a sales plan', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create([
        'name' => 'March territory plan',
        'created_by' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test(ViewMonthlyPlan::class, ['record' => $plan->getKey()])
        ->assertSuccessful()
        ->assertSee('March territory plan')
        ->assertSee($plan->status->value)
        ->assertSee('Record Information')
        ->assertSee('Scoring Weights')
        ->assertSee($admin->name)
        ->assertSee('No performance score calculated yet.');
});

it('renders the performance score as a progress line instead of a table', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create();
    EmployeePerformanceScore::factory()->create([
        'sales_plan_id' => $plan->id,
        'employee_id' => $plan->employee_id,
        'total_score' => 87.5,
        'task_score' => 40,
        'visit_score' => 25.5,
        'schedule_score' => 15,
        'work_time_score' => 7,
    ]);

    Livewire::actingAs($admin)
        ->test(ViewMonthlyPlan::class, ['record' => $plan->getKey()])
        ->assertSuccessful()
        ->assertSee('87.50%')
        ->assertSee('Task score: 40.00')
        ->assertSee('Visit score: 25.50')
        ->assertSee('Schedule score: 15.00')
        ->assertSee('Work time score: 7.00');
});

it('colors the performance bar green when the score reaches 100%', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create();
    EmployeePerformanceScore::factory()->create([
        'sales_plan_id' => $plan->id,
        'employee_id' => $plan->employee_id,
        'total_score' => 100,
        'task_score' => 50,
        'visit_score' => 30,
        'schedule_score' => 15,
        'work_time_score' => 5,
    ]);

    Livewire::actingAs($admin)
        ->test(ViewMonthlyPlan::class, ['record' => $plan->getKey()])
        ->assertSuccessful()
        ->assertSeeHtml('performance-progress-bar-fill-complete');
});

it('shows the required visit minutes when explicitly set on the plan', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['required_visit_minutes' => 45]);

    Livewire::actingAs($admin)
        ->test(ViewMonthlyPlan::class, ['record' => $plan->getKey()])
        ->assertSuccessful()
        ->assertSee('45');
});

it('falls back to the configured default when required visit minutes is not set', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['required_visit_minutes' => null]);

    Livewire::actingAs($admin)
        ->test(ViewMonthlyPlan::class, ['record' => $plan->getKey()])
        ->assertSuccessful()
        ->assertSee((string) config('employees.default_required_visit_minutes'));
});

it('formats the configured visit-minute fallback and malformed performance factors', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['required_visit_minutes' => null]);
    $score = EmployeePerformanceScore::factory()->create([
        'sales_plan_id' => $plan->id,
        'employee_id' => $plan->employee_id,
        'calculation_breakdown' => ['task_completion' => 'malformed'],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(ViewMonthlyPlan::class, ['record' => $plan->getKey()])
        ->instance();
    $entry = $component->getSchema('infolist')->getComponent('required_visit_minutes');

    expect($entry->formatState(null))->toBe((string) config('employees.default_required_visit_minutes'))
        ->and(new ReflectionMethod(PerformanceInfolist::class, 'factorRows')->invoke(null, $score))->toBe([]);
});

it('renders the stage bar for every plan status', function (string $status): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['status' => $status]);

    Livewire::actingAs($admin)
        ->test(ViewMonthlyPlan::class, ['record' => $plan->getKey()])
        ->assertSuccessful()
        ->assertSee($status);
})->with(['Draft', 'Active', 'Paused', 'Completed', 'Archived']);
