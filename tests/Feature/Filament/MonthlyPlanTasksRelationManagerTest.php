<?php

declare(strict_types=1);

use App\Filament\Resources\MonthlyPlans\Pages\EditMonthlyPlan;
use App\Filament\Resources\MonthlyPlans\Pages\ViewMonthlyPlan;
use App\Filament\Resources\MonthlyPlans\RelationManagers\TasksRelationManager;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('searches plan tasks by title', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create();

    $match = PlanTask::factory()->create(['sales_plan_id' => $plan->id, 'title' => 'Final handover visit']);
    $other = PlanTask::factory()->create(['sales_plan_id' => $plan->id, 'title' => 'Close out territory notes']);

    Livewire::actingAs($admin)
        ->test(TasksRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => ViewMonthlyPlan::class,
        ])
        ->searchTable('handover')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('defaults the create task dates to the plan month bounds', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);

    Livewire::actingAs($admin)
        ->test(TasksRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => EditMonthlyPlan::class,
        ])
        ->mountAction(TestAction::make('create')->table())
        ->assertActionDataSet([
            'starts_at' => '2026-03-01',
            'due_at' => '2026-03-31',
        ]);
});

it('filters plan tasks by status, overdue, and due soon', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create();

    $overdue = PlanTask::factory()->overdue()->create(['sales_plan_id' => $plan->id]);
    $dueSoon = PlanTask::factory()->create([
        'sales_plan_id' => $plan->id,
        'starts_at' => now()->toDateString(),
        'due_at' => now()->addDays(2)->toDateString(),
    ]);
    $completed = PlanTask::factory()->completed()->create(['sales_plan_id' => $plan->id]);
    $farOut = PlanTask::factory()->create([
        'sales_plan_id' => $plan->id,
        'starts_at' => now()->toDateString(),
        'due_at' => now()->addDays(30)->toDateString(),
    ]);

    Livewire::actingAs($admin)
        ->test(TasksRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => ViewMonthlyPlan::class,
        ])
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$dueSoon, $completed, $farOut])
        ->removeTableFilter('overdue')
        ->filterTable('due_soon')
        ->assertCanSeeTableRecords([$dueSoon])
        ->assertCanNotSeeTableRecords([$overdue, $completed, $farOut])
        ->removeTableFilter('due_soon')
        ->filterTable('status', $completed->status->value)
        ->assertCanSeeTableRecords([$completed])
        ->assertCanNotSeeTableRecords([$overdue, $dueSoon, $farOut]);
});
