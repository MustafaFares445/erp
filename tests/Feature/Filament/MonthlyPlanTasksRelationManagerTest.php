<?php

declare(strict_types=1);

use App\Enums\PlanTaskStatus;
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

it('creates a task through the create action', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);

    Livewire::actingAs($admin)
        ->test(TasksRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => EditMonthlyPlan::class,
        ])
        ->callAction(TestAction::make('create')->table(), [
            'title' => 'Follow up call',
            'starts_at' => '2026-03-05',
            'due_at' => '2026-03-10',
        ])
        ->assertHasNoActionErrors();

    expect(PlanTask::query()->where('sales_plan_id', $plan->id)->where('title', 'Follow up call')->exists())->toBeTrue();
});

it('updates a task through the edit action', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);
    $task = PlanTask::factory()->create([
        'sales_plan_id' => $plan->id,
        'title' => 'Old title',
        'starts_at' => '2026-03-05',
        'due_at' => '2026-03-10',
    ]);

    Livewire::actingAs($admin)
        ->test(TasksRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => EditMonthlyPlan::class,
        ])
        ->callAction(TestAction::make('edit')->table($task), [
            'title' => 'New title',
        ])
        ->assertHasNoActionErrors();

    expect($task->fresh()->title)->toBe('New title');
});

it('shows a notification instead of crashing when a task transition violates the status machine', function (): void {
    $plan = SalesPlan::factory()->create();
    $task = PlanTask::factory()->completed()->create(['sales_plan_id' => $plan->id]);

    $applyTransition = new ReflectionMethod(TasksRelationManager::class, 'applyTransition');
    $applyTransition->invoke(null, $task, PlanTaskStatus::Cancelled, null);

    expect($task->fresh()->status)->toBe(PlanTaskStatus::Completed);
});

it('passes a missing transition note as null to the task service', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create();
    $task = PlanTask::factory()->for($plan)->create(['status' => PlanTaskStatus::Pending]);

    $action = new ReflectionMethod(TasksRelationManager::class, 'transitionAction')
        ->invoke(null, 'startProgress', 'Start progress', PlanTaskStatus::InProgress);

    $this->actingAs($admin);
    ($action->getActionFunction())($task, []);

    expect($task->fresh()->status)->toBe(PlanTaskStatus::InProgress);
});

it('throws a LogicException when the owner record is somehow not a SalesPlan', function (): void {
    $manager = new TasksRelationManager;
    $manager->ownerRecord = PlanTask::factory()->create();

    $plan = new ReflectionMethod($manager, 'plan');

    expect(fn (): SalesPlan => $plan->invoke($manager))
        ->toThrow(LogicException::class, 'Expected the owner record of TasksRelationManager to be a SalesPlan.');
});
