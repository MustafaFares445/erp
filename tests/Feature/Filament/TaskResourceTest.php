<?php

declare(strict_types=1);

use App\Enums\PlanTaskStatus;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('denies the task list, view, and edit pages without the task permissions', function (): void {
    $user = User::factory()->admin()->create();
    $user->assignRole('Payroll Officer');

    $task = PlanTask::factory()->create();

    $this->actingAs($user)
        ->get(TaskResource::getUrl('index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(TaskResource::getUrl('view', ['record' => $task]))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(TaskResource::getUrl('edit', ['record' => $task]))
        ->assertForbidden();
});

it('lists tasks with their plan name through the admin-scoped eloquent query', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['name' => 'Q1 Growth Plan']);
    $task = PlanTask::factory()->create(['sales_plan_id' => $plan->id, 'title' => 'Visit key account']);

    Livewire::actingAs($admin)
        ->test(ListTasks::class)
        ->assertCanSeeTableRecords([$task])
        ->assertSee('Q1 Growth Plan')
        ->assertSee('Visit key account');
});

it('shows the task detail page with an edit action available', function (): void {
    $admin = User::factory()->admin()->create();
    $task = PlanTask::factory()->create(['title' => 'Follow up with client']);

    Livewire::actingAs($admin)
        ->test(ViewTask::class, ['record' => $task->getKey()])
        ->assertSuccessful()
        ->assertFormSet(['title' => 'Follow up with client'])
        ->assertActionExists('edit');
});

it('shows the task plan placeholder and updates a task through the edit form', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['name' => 'Renewal Plan', 'month' => now()->startOfMonth()->toDateString()]);
    $task = PlanTask::factory()->create([
        'sales_plan_id' => $plan->id,
        'title' => 'Old title',
        'starts_at' => now()->startOfMonth()->toDateString(),
        'due_at' => now()->endOfMonth()->toDateString(),
    ]);

    Livewire::actingAs($admin)
        ->test(EditTask::class, ['record' => $task->getKey()])
        ->assertSuccessful()
        ->assertSee('Renewal Plan')
        ->fillForm([
            'title' => 'New title',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($task->fresh()->title)->toBe('New title');
});

it('notifies instead of crashing when a task update violates the plan window domain rule', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['month' => now()->startOfMonth()->toDateString()]);
    $task = PlanTask::factory()->create([
        'sales_plan_id' => $plan->id,
        'title' => 'Original title',
        'starts_at' => now()->startOfMonth()->toDateString(),
        'due_at' => now()->endOfMonth()->toDateString(),
    ]);

    Livewire::actingAs($admin)
        ->test(EditTask::class, ['record' => $task->getKey()])
        ->fillForm([
            'title' => 'Should not persist',
            'starts_at' => now()->addMonths(2)->startOfMonth()->toDateString(),
            'due_at' => now()->addMonths(2)->endOfMonth()->toDateString(),
        ])
        ->call('save')
        ->assertNotified();

    expect($task->fresh()->title)->toBe('Original title');
});

it('rejects a non-task record in the edit handler', function (): void {
    $page = new EditTask;
    $method = new ReflectionMethod(EditTask::class, 'handleRecordUpdate');

    expect(fn (): mixed => $method->invoke($page, new User, []))
        ->toThrow(LogicException::class);
});

it('filters tasks by their overdue, due-soon, and completed states from the resource table', function (): void {
    $admin = User::factory()->admin()->create();

    $overdue = PlanTask::factory()->overdue()->create();
    $dueSoon = PlanTask::factory()->create([
        'starts_at' => now()->toDateString(),
        'due_at' => now()->addDays(2)->toDateString(),
    ]);
    $completed = PlanTask::factory()->completed()->create();

    Livewire::actingAs($admin)
        ->test(ListTasks::class)
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$dueSoon, $completed])
        ->removeTableFilter('overdue')
        ->filterTable('due_soon')
        ->assertCanSeeTableRecords([$dueSoon])
        ->assertCanNotSeeTableRecords([$overdue, $completed])
        ->removeTableFilter('due_soon')
        ->filterTable('completed')
        ->assertCanSeeTableRecords([$completed])
        ->assertCanNotSeeTableRecords([$overdue, $dueSoon]);
});

it('transitions a pending task to in progress through the table action', function (): void {
    $admin = User::factory()->admin()->create();
    $task = PlanTask::factory()->create(['status' => PlanTaskStatus::Pending]);

    Livewire::actingAs($admin)
        ->test(ListTasks::class)
        ->callTableAction('startProgress', $task, data: ['note' => 'Starting now'])
        ->assertHasNoTableActionErrors();

    expect($task->fresh()->status)->toBe(PlanTaskStatus::InProgress);
});

it('completes, cancels, and reopens tasks through their table actions', function (): void {
    $admin = User::factory()->admin()->create();

    $toComplete = PlanTask::factory()->create(['status' => PlanTaskStatus::Pending]);
    $toCancel = PlanTask::factory()->create(['status' => PlanTaskStatus::Pending]);
    $toReopen = PlanTask::factory()->completed()->create();

    $list = Livewire::actingAs($admin)->test(ListTasks::class);

    $list->callTableAction('complete', $toComplete, data: ['note' => 'Done'])
        ->assertHasNoTableActionErrors();
    $list->callTableAction('cancel', $toCancel, data: ['note' => 'No longer needed'])
        ->assertHasNoTableActionErrors();
    $list->callTableAction('reopen', $toReopen, data: ['note' => 'Reopening for follow-up'])
        ->assertHasNoTableActionErrors();

    expect($toComplete->fresh()->status)->toBe(PlanTaskStatus::Completed)
        ->and($toCancel->fresh()->status)->toBe(PlanTaskStatus::Cancelled)
        ->and($toReopen->fresh()->status)->toBe(PlanTaskStatus::InProgress);
});

it('shows a notification instead of crashing when a table transition violates the status lifecycle', function (): void {
    $task = PlanTask::factory()->create(['status' => PlanTaskStatus::Cancelled]);

    $method = new ReflectionMethod(TasksTable::class, 'transition');
    $method->invoke(null, $task, PlanTaskStatus::Completed, 'Invalid jump');

    expect($task->fresh()->status)->toBe(PlanTaskStatus::Cancelled);
});
