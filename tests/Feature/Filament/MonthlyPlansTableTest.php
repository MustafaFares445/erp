<?php

declare(strict_types=1);

use App\Filament\Resources\MonthlyPlans\Pages\ListMonthlyPlans;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('activates a draft plan and shows a notification when the weights do not sum to 100', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create([
        'task_weight' => 50,
        'visit_weight' => 30,
        'schedule_weight' => 20,
        'work_time_weight' => 10,
    ]);

    Livewire::actingAs($admin)
        ->test(ListMonthlyPlans::class)
        ->callAction(TestAction::make('activate')->table($plan))
        ->assertNotified();

    expect($plan->fresh()->status->value)->toBe('Draft');
});

it('activates a draft plan with valid weights and at least one task', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create();
    PlanTask::factory()->create(['sales_plan_id' => $plan->id]);

    Livewire::actingAs($admin)
        ->test(ListMonthlyPlans::class)
        ->callAction(TestAction::make('activate')->table($plan));

    expect($plan->fresh()->status->value)->toBe('Active');
});

it('pauses, completes, and archives a plan through the transition actions', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->active()->create();

    Livewire::actingAs($admin)
        ->test(ListMonthlyPlans::class)
        ->callAction(TestAction::make('pause')->table($plan));

    expect($plan->fresh()->status->value)->toBe('Paused');
});

it('shows a notification instead of deleting a plan that has completed tasks', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create();
    PlanTask::factory()->completed()->create(['sales_plan_id' => $plan->id]);

    Livewire::actingAs($admin)
        ->test(ListMonthlyPlans::class)
        ->callAction(TestAction::make('delete')->table($plan))
        ->assertNotified();

    expect($plan->fresh())->not->toBeNull();
});

it('deletes and restores a plan without completed tasks', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create();

    Livewire::actingAs($admin)
        ->test(ListMonthlyPlans::class)
        ->callAction(TestAction::make('delete')->table($plan));

    expect($plan->fresh()->trashed())->toBeTrue();

    Livewire::actingAs($admin)
        ->test(ListMonthlyPlans::class)
        ->callAction(TestAction::make('restore')->table($plan->fresh()));

    expect($plan->fresh()->trashed())->toBeFalse()
        ->and($plan->fresh()->status->value)->toBe('Archived');
});

it('copies a plan to another month', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);

    Livewire::actingAs($admin)
        ->test(ListMonthlyPlans::class)
        ->callAction(TestAction::make('copyToMonth')->table($plan), [
            'target_month' => '2026-04-01',
        ])
        ->assertHasNoActionErrors();

    expect(SalesPlan::query()->where('employee_id', $plan->employee_id)->whereDate('month', '2026-04-01')->exists())->toBeTrue();
});

it('shows a notification instead of copying a plan into a conflicting target month', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);
    SalesPlan::factory()->create([
        'employee_id' => $plan->employee_id,
        'month' => '2026-04-01',
        'active_month' => '2026-04-01',
    ]);

    Livewire::actingAs($admin)
        ->test(ListMonthlyPlans::class)
        ->callAction(TestAction::make('copyToMonth')->table($plan), [
            'target_month' => '2026-04-01',
        ])
        ->assertNotified();
});

it('assigns a plan to another employee', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create();
    $targetEmployee = EmployeeProfile::factory()->create();

    Livewire::actingAs($admin)
        ->test(ListMonthlyPlans::class)
        ->callAction(TestAction::make('assignToEmployee')->table($plan), [
            'target_employee_id' => $targetEmployee->id,
        ])
        ->assertHasNoActionErrors();

    expect(SalesPlan::query()->where('employee_id', $targetEmployee->id)->where('name', $plan->name)->exists())->toBeTrue();
});

it('shows a notification instead of assigning a plan to an employee with a conflicting active plan', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);
    $targetEmployee = EmployeeProfile::factory()->create();
    SalesPlan::factory()->create([
        'employee_id' => $targetEmployee->id,
        'month' => '2026-03-01',
        'active_month' => '2026-03-01',
    ]);

    Livewire::actingAs($admin)
        ->test(ListMonthlyPlans::class)
        ->callAction(TestAction::make('assignToEmployee')->table($plan), [
            'target_employee_id' => $targetEmployee->id,
        ])
        ->assertNotified();
});
