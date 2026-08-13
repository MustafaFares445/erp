<?php

declare(strict_types=1);

use App\Filament\Resources\MonthlyPlans\Pages\ViewMonthlyPlan;
use App\Filament\Resources\MonthlyPlans\RelationManagers\VisitsRelationManager;
use App\Models\CustomerVisit;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists visits attributed to this plan and excludes visits from other plans', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create();

    $task = PlanTask::factory()->create(['sales_plan_id' => $plan->id]);
    $ownVisit = CustomerVisit::factory()->for($task, 'planTask')->create();

    $otherTask = PlanTask::factory()->create();
    $otherPlanVisit = CustomerVisit::factory()->for($otherTask, 'planTask')->create();

    Livewire::actingAs($admin)
        ->test(VisitsRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => ViewMonthlyPlan::class,
        ])
        ->assertOk()
        ->assertCanSeeTableRecords([$ownVisit])
        ->assertCanNotSeeTableRecords([$otherPlanVisit]);
});

it('shows the visit duration for a completed visit', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create();
    $task = PlanTask::factory()->create(['sales_plan_id' => $plan->id]);
    $visit = CustomerVisit::factory()->completed()->for($task, 'planTask')->create();

    Livewire::actingAs($admin)
        ->test(VisitsRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => ViewMonthlyPlan::class,
        ])
        ->assertOk()
        ->assertSee($visit->durationMinutes().' min');
});

it('opens the visit infolist through the view action', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create();
    $task = PlanTask::factory()->create(['sales_plan_id' => $plan->id]);
    $visit = CustomerVisit::factory()->for($task, 'planTask')->create();

    Livewire::actingAs($admin)
        ->test(VisitsRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => ViewMonthlyPlan::class,
        ])
        ->callAction(TestAction::make('view')->table($visit))
        ->assertOk();
});
