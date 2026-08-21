<?php

declare(strict_types=1);

use App\Enums\BonusSuggestionStatus;
use App\Filament\Resources\SalaryCalculations\Pages\ViewSalaryCalculation;
use App\Filament\Resources\SalaryCalculations\RelationManagers\BonusSuggestionsRelationManager;
use App\Models\BonusSuggestion;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\SalesPlan;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('lists bonus suggestions sharing the calculation plan and creates a new suggestion', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $employee->id]);
    $calculation = EmployeeSalaryCalculation::factory()->create([
        'employee_id' => $employee->id,
        'sales_plan_id' => $plan->id,
    ]);
    $suggestion = BonusSuggestion::factory()->create([
        'employee_id' => $employee->id,
        'sales_plan_id' => $plan->id,
    ]);
    $unrelated = BonusSuggestion::factory()->create();

    Livewire::actingAs($admin)
        ->test(BonusSuggestionsRelationManager::class, [
            'ownerRecord' => $calculation,
            'pageClass' => ViewSalaryCalculation::class,
        ])
        ->assertCanSeeTableRecords([$suggestion])
        ->assertCanNotSeeTableRecords([$unrelated])
        ->callTableAction('create', data: [
            'amount' => 150,
            'reason' => 'Closed a large deal outside the normal plan.',
        ])
        ->assertHasNoActionErrors();

    $created = BonusSuggestion::query()->where('reason', 'Closed a large deal outside the normal plan.')->sole();

    expect($created->sales_plan_id)->toBe($plan->id)
        ->and($created->employee_id)->toBe($employee->id)
        ->and($created->status)->toBe(BonusSuggestionStatus::Pending);
});

it('only allows editing and deleting a bonus suggestion while it is pending', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $employee->id]);
    $calculation = EmployeeSalaryCalculation::factory()->create([
        'employee_id' => $employee->id,
        'sales_plan_id' => $plan->id,
    ]);
    $pending = BonusSuggestion::factory()->create(['employee_id' => $employee->id, 'sales_plan_id' => $plan->id]);
    $approved = BonusSuggestion::factory()->approved()->create(['employee_id' => $employee->id, 'sales_plan_id' => $plan->id]);

    $component = Livewire::actingAs($admin)
        ->test(BonusSuggestionsRelationManager::class, [
            'ownerRecord' => $calculation,
            'pageClass' => ViewSalaryCalculation::class,
        ]);

    $component->assertTableActionVisible('edit', $pending)
        ->assertTableActionVisible('delete', $pending)
        ->assertTableActionHidden('edit', $approved)
        ->assertTableActionHidden('delete', $approved)
        ->assertTableActionVisible('approve', $pending)
        ->assertTableActionVisible('reject', $pending)
        ->assertTableActionHidden('approve', $approved)
        ->assertTableActionHidden('reject', $approved);
});

it('approves a pending bonus suggestion via the decision action', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $employee->id]);
    $calculation = EmployeeSalaryCalculation::factory()->create([
        'employee_id' => $employee->id,
        'sales_plan_id' => $plan->id,
    ]);
    $suggestion = BonusSuggestion::factory()->create(['employee_id' => $employee->id, 'sales_plan_id' => $plan->id]);

    Livewire::actingAs($admin)
        ->test(BonusSuggestionsRelationManager::class, [
            'ownerRecord' => $calculation,
            'pageClass' => ViewSalaryCalculation::class,
        ])
        ->callTableAction('approve', $suggestion, ['decision_notes' => 'Confirmed with the sales lead.']);

    expect($suggestion->fresh()->status)->toBe(BonusSuggestionStatus::Approved)
        ->and($suggestion->fresh()->approved_by)->toBe($admin->id)
        ->and($suggestion->fresh()->decision_notes)->toBe('Confirmed with the sales lead.');
});

it('rejects a pending bonus suggestion via the decision action', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $employee->id]);
    $calculation = EmployeeSalaryCalculation::factory()->create([
        'employee_id' => $employee->id,
        'sales_plan_id' => $plan->id,
    ]);
    $suggestion = BonusSuggestion::factory()->create(['employee_id' => $employee->id, 'sales_plan_id' => $plan->id]);

    Livewire::actingAs($admin)
        ->test(BonusSuggestionsRelationManager::class, [
            'ownerRecord' => $calculation,
            'pageClass' => ViewSalaryCalculation::class,
        ])
        ->callTableAction('reject', $suggestion, ['decision_notes' => null]);

    expect($suggestion->fresh()->status)->toBe(BonusSuggestionStatus::Rejected)
        ->and($suggestion->fresh()->approved_by)->toBe($admin->id);
});

it('notifies instead of throwing when a decision action targets an already-decided suggestion', function (): void {
    $suggestion = BonusSuggestion::factory()->approved()->create();

    $decisionAction = new ReflectionMethod(BonusSuggestionsRelationManager::class, 'decisionAction');
    $action = $decisionAction->invoke(null, 'approve', 'Approve', 'success', Heroicon::OutlinedCheckCircle);

    $closure = $action->getActionFunction();

    expect($closure)->not->toBeNull();

    $closure($suggestion, ['decision_notes' => 'Too late']);

    expect($suggestion->fresh()->status)->toBe(BonusSuggestionStatus::Approved)
        ->and(session('filament.notifications'))->not->toBeEmpty();
});

it('throws when the owner record is not a salary calculation', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->create();

    $relationManager = new BonusSuggestionsRelationManager;
    $relationManager->ownerRecord = $employee;

    $calculation = new ReflectionMethod(BonusSuggestionsRelationManager::class, 'calculation');

    expect(fn (): mixed => $calculation->invoke($relationManager))->toThrow(LogicException::class);
});
