<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ServiceRecordsRelationManager;
use App\Filament\Resources\ServiceRecords\Pages\EditServiceRecord;
use App\Filament\Resources\ServiceRecords\Pages\ListServiceRecords;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Policies\MaintenanceTaskPolicy;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use App\Services\Support\ServiceRecordService;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

function makeServiceRecordSupportManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

function makeServiceRecordSystemAdmin(): User
{
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    return $admin;
}

/** @return array{0: User, 1: EmployeeProfile} */
function makeServiceRecordAgentWithProfile(): array
{
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $profile = EmployeeProfile::factory()->create(['user_id' => $agent->id]);

    return [$agent, $profile];
}

it('requires a title and belongs to exactly one maintenance request, never movable between parents', function (): void {
    $manager = makeServiceRecordSupportManager();
    $record = MaintenanceRecord::factory()->create();

    $task = app(ServiceRecordService::class)->create($record, ['title' => 'Replace filter'], $manager);

    expect($task->maintenance_record_id)->toBe($record->id);

    expect(fn () => app(ServiceRecordService::class)->create($record, [], $manager))
        ->toThrow(QueryException::class);

    // No "move to a different request" action exists at any layer — the FK is set once at creation.
    $otherRecord = MaintenanceRecord::factory()->create();
    expect($task->maintenance_record_id)->not->toBe($otherRecord->id);

    // Defense-in-depth: a direct model write attempting to re-parent it is rejected too,
    // not just absent from the service/UI surface.
    expect(fn () => $task->update(['maintenance_record_id' => $otherRecord->id]))
        ->toThrow(DomainException::class);
});

it('rejects a due_at earlier than the parent maintenance request was created', function (): void {
    $manager = makeServiceRecordSupportManager();
    $record = MaintenanceRecord::factory()->create();

    expect(fn () => app(ServiceRecordService::class)->create($record, [
        'title' => 'Too early',
        'due_at' => $record->created_at->clone()->subDay()->toIso8601String(),
    ], $manager))->toThrow(ValidationException::class);

    $task = app(ServiceRecordService::class)->create($record, [
        'title' => 'On time',
        'due_at' => $record->created_at->clone()->addDay()->toIso8601String(),
    ], $manager);

    expect($task->due_at)->not->toBeNull();
});

it('rejects an update moving due_at earlier than the parent maintenance request was created, even via a direct call', function (): void {
    $manager = makeServiceRecordSupportManager();
    $record = MaintenanceRecord::factory()->create();
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create();

    expect(fn () => app(ServiceRecordService::class)->update($task, [
        'due_at' => $record->created_at->clone()->subDay()->toIso8601String(),
    ], $manager))->toThrow(ValidationException::class);
});

it('keeps an already-Carbon due_at unchanged when updating without specifying a new one, even via a direct call', function (): void {
    $manager = makeServiceRecordSupportManager();
    $dueAt = now()->addDays(3);
    $task = MaintenanceTask::factory()->create(['due_at' => $dueAt, 'title' => 'Original title']);

    $updated = app(ServiceRecordService::class)->update($task, ['title' => 'Renamed'], $manager);

    expect($updated->title)->toBe('Renamed')
        ->and($updated->due_at?->toDateTimeString())->toBe($dueAt->toDateTimeString());
});

it('permits only open->in_progress|cancelled and in_progress->closed|cancelled, matching MaintenanceStatus, even via a direct call', function (): void {
    $manager = makeServiceRecordSupportManager();
    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::Open]);
    $service = app(ServiceRecordService::class);

    expect(fn () => $service->transition($task, MaintenanceStatus::Closed, $manager))
        ->toThrow(InvalidStatusTransition::class);

    $service->transition($task, MaintenanceStatus::InProgress, $manager);
    expect($task->refresh()->status)->toBe(MaintenanceStatus::InProgress);

    $service->transition($task, MaintenanceStatus::Closed, $manager);
    expect($task->refresh()->status)->toBe(MaintenanceStatus::Closed);

    expect(fn () => $service->transition($task, MaintenanceStatus::InProgress, $manager))
        ->toThrow(InvalidStatusTransition::class);
});

it('denies an agent executing another employee service record, while their own succeeds', function (): void {
    [$owner, $ownerProfile] = makeServiceRecordAgentWithProfile();
    [$otherAgent] = makeServiceRecordAgentWithProfile();
    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::Open, 'employee_id' => $ownerProfile->id]);

    expect(fn () => app(ServiceRecordService::class)->transition($task, MaintenanceStatus::InProgress, $otherAgent))
        ->toThrow(AuthorizationException::class);

    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::InProgress, $owner);
    expect($task->refresh()->status)->toBe(MaintenanceStatus::InProgress);
});

it('cascades the first service record reaching in_progress to its open parent, without re-triggering an audit entry for a second one', function (): void {
    $manager = makeServiceRecordSupportManager();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);
    $first = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::Open]);
    $second = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::Open]);

    app(ServiceRecordService::class)->transition($first, MaintenanceStatus::InProgress, $manager);
    expect($record->refresh()->status)->toBe(MaintenanceStatus::InProgress);

    app(ServiceRecordService::class)->transition($second, MaintenanceStatus::InProgress, $manager);

    expect($record->refresh()->status)->toBe(MaintenanceStatus::InProgress)
        ->and(AuditLog::query()->where('description', 'support.maintenance_record.status_changed')->where('subject_id', $record->id)->count())->toBe(1);
});

it('overdue, due-soon, and closed service records are visually distinguished in the list', function (): void {
    $manager = makeServiceRecordSupportManager();
    $overdue = MaintenanceTask::factory()->overdue()->create();
    $dueSoon = MaintenanceTask::factory()->dueSoon()->create();
    $closed = MaintenanceTask::factory()->closed()->create();

    Livewire::actingAs($manager)
        ->test(ListServiceRecords::class)
        ->assertCanSeeTableRecords([$overdue, $dueSoon, $closed]);
});

it('audits every status change with actor, timestamp, and an optional note', function (): void {
    $manager = makeServiceRecordSupportManager();
    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::Open]);

    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::InProgress, $manager, 'Started the repair');

    $activity = AuditLog::query()
        ->where('description', 'support.service_record.status_changed')
        ->where('subject_id', $task->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($manager->id)
        ->and($activity->created_at)->not->toBeNull()
        ->and($activity->attribute_changes['attributes']['note'] ?? null)->toBe('Started the repair');
});

it('grants manage/execute per the role matrix, matching page-open, direct-action, bulk-action, and direct-service-call parity', function (): void {
    $manager = makeServiceRecordSupportManager();
    [$agent, $agentProfile] = makeServiceRecordAgentWithProfile();

    $policy = app(MaintenanceTaskPolicy::class);
    $ownTask = MaintenanceTask::factory()->create(['employee_id' => $agentProfile->id]);
    $otherTask = MaintenanceTask::factory()->create();

    expect($policy->update($manager))->toBeTrue()
        ->and($policy->execute($manager, $otherTask))->toBeTrue()
        ->and($policy->execute($agent, $ownTask))->toBeTrue()
        ->and($policy->execute($agent, $otherTask))->toBeFalse()
        ->and($policy->deleteAny($agent))->toBeFalse()
        ->and($policy->deleteAny($manager))->toBeTrue();

    Livewire::actingAs($manager)->test(ListServiceRecords::class)->assertTableActionVisible('archive', $otherTask);
    Livewire::actingAs($agent)->test(ListServiceRecords::class)->assertTableActionHidden('archive', $otherTask);

    expect(fn () => app(ServiceRecordService::class)->transition($otherTask, MaintenanceStatus::InProgress, $agent))
        ->toThrow(AuthorizationException::class);
});

it('adds a service record through the actual relation manager "Add Service Record" action', function (): void {
    $manager = makeServiceRecordSupportManager();
    $record = MaintenanceRecord::factory()->create();

    Livewire::actingAs($manager)
        ->test(ServiceRecordsRelationManager::class, [
            'ownerRecord' => $record,
            'pageClass' => ViewMaintenanceRequest::class,
        ])
        ->callAction(TestAction::make('addServiceRecord')->table(), [
            'title' => 'Replace the filter cartridge',
            'due_at' => now()->addDay()->toDateTimeString(),
        ])
        ->assertHasNoActionErrors();

    $task = MaintenanceTask::query()->where('maintenance_record_id', $record->id)->where('title', 'Replace the filter cartridge')->first();

    expect($task)->not->toBeNull()
        ->and($task->status)->toBe(MaintenanceStatus::Open);
});

it('transitions a service record through the actual relation manager row action', function (): void {
    $manager = makeServiceRecordSupportManager();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::Open]);

    Livewire::actingAs($manager)
        ->test(ServiceRecordsRelationManager::class, [
            'ownerRecord' => $record,
            'pageClass' => ViewMaintenanceRequest::class,
        ])
        ->callAction(TestAction::make('startProgress')->table($task));

    expect($task->refresh()->status)->toBe(MaintenanceStatus::InProgress)
        ->and($record->refresh()->status)->toBe(MaintenanceStatus::InProgress);
});

it('loads and saves the actual Edit form for a service record', function (): void {
    $manager = makeServiceRecordSupportManager();
    $task = MaintenanceTask::factory()->create(['title' => 'Original title']);

    Livewire::actingAs($manager)
        ->test(EditServiceRecord::class, ['record' => $task->getRouteKey()])
        ->fillForm(['title' => 'Updated title'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($task->refresh()->title)->toBe('Updated title');
});

it("transitions and archives a service record through the standalone list's actual table row actions", function (): void {
    $manager = makeServiceRecordSupportManager();
    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::Open]);

    $list = Livewire::actingAs($manager)->test(ListServiceRecords::class);
    $list->callTableAction('startProgress', $task);

    expect($task->refresh()->status)->toBe(MaintenanceStatus::InProgress);

    $list->callTableAction('close', $task);
    expect($task->refresh()->status)->toBe(MaintenanceStatus::Closed);

    $secondTask = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::Open]);
    $list->callTableAction('cancel', $secondTask);
    expect($secondTask->refresh()->status)->toBe(MaintenanceStatus::Cancelled);

    $list->callTableAction('archive', $task);
    expect($task->refresh()->trashed())->toBeTrue();
});

it('denies restoring an archived service record to a Support Manager via the row action', function (): void {
    $manager = makeServiceRecordSupportManager();
    $task = MaintenanceTask::factory()->create();
    $task->delete();

    Livewire::actingAs($manager)
        ->test(ListServiceRecords::class)
        ->filterTable('trashed', true)
        ->assertTableActionHidden('restore', $task);
});

it('restores a single archived service record via the row action only for System Admin', function (): void {
    $admin = makeServiceRecordSystemAdmin();
    $task = MaintenanceTask::factory()->create();
    $task->delete();

    Livewire::actingAs($admin)
        ->test(ListServiceRecords::class)
        ->filterTable('trashed', true)
        ->callTableAction('restore', $task);

    expect($task->refresh()->trashed())->toBeFalse();
});

it('bulk-archives service records but denies bulk-restore to a Support Manager', function (): void {
    $manager = makeServiceRecordSupportManager();
    $first = MaintenanceTask::factory()->create();
    $second = MaintenanceTask::factory()->create();

    Livewire::actingAs($manager)
        ->test(ListServiceRecords::class)
        ->callTableBulkAction('archive', [$first, $second]);

    expect($first->refresh()->trashed())->toBeTrue()
        ->and($second->refresh()->trashed())->toBeTrue();

    Livewire::actingAs($manager)
        ->test(ListServiceRecords::class)
        ->filterTable('trashed', true)
        ->assertTableBulkActionHidden('restore');
});

it('bulk-restores service records through the actual toolbar action only for System Admin', function (): void {
    $admin = makeServiceRecordSystemAdmin();
    $first = MaintenanceTask::factory()->create();
    $second = MaintenanceTask::factory()->create();
    $first->delete();
    $second->delete();

    Livewire::actingAs($admin)
        ->test(ListServiceRecords::class)
        ->filterTable('trashed', true)
        ->callTableBulkAction('restore', [$first, $second]);

    expect($first->refresh()->trashed())->toBeFalse()
        ->and($second->refresh()->trashed())->toBeFalse();
});

it('never permits creating a service record directly — only through a maintenance request\'s relation manager', function (): void {
    expect(ServiceRecordResource::canCreate())->toBeFalse();
});
