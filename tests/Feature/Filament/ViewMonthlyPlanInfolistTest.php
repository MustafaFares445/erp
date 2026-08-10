<?php

declare(strict_types=1);

use App\Filament\Resources\MonthlyPlans\Pages\ViewMonthlyPlan;
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
