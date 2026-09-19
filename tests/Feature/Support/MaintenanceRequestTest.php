<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\WarrantyStatus;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\MaintenanceRequests\Pages\CreateMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\Pages\EditMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\Pages\ListMaintenanceRequests;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\MaintenanceRecordsRelationManager;
use App\Models\CustomerProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\MaintenanceRecordPolicy;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use App\Services\Support\MaintenanceRecordService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

function makeMaintenanceSupportManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

function makeMaintenanceSystemAdmin(): User
{
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    return $admin;
}

it('pre-fills customer and description when raised from a triaged maintenance ticket, and links both ways', function (): void {
    $manager = makeMaintenanceSupportManager();
    $ticket = Ticket::factory()->triagedForMaintenance()->create();

    $record = app(MaintenanceRecordService::class)->createFromTicket($ticket, [
        'description' => $ticket->description,
    ], $manager);

    expect($record->customer_id)->toBe($ticket->customer_id)
        ->and($record->ticket_id)->toBe($ticket->id)
        ->and($record->description)->toBe($ticket->description)
        ->and($record->warranty_status)->toBe(WarrantyStatus::NotApplicable)
        ->and($ticket->maintenanceRecords()->whereKey($record->id)->exists())->toBeTrue();

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->assertSuccessful();

    Livewire::actingAs($manager)
        ->test(MaintenanceRecordsRelationManager::class, [
            'ownerRecord' => $ticket,
            'pageClass' => ViewTicket::class,
        ])
        ->assertSuccessful()
        ->assertSee(MaintenanceRequestResource::getUrl('view', ['record' => $record->getKey()]));
});

it('pre-fills the actual Create form from a triaged maintenance ticket query parameter', function (): void {
    $manager = makeMaintenanceSupportManager();
    $ticket = Ticket::factory()->triagedForMaintenance()->create(['description' => 'Pre-filled from the ticket']);

    Livewire::actingAs($manager)
        ->withQueryParams(['ticket_id' => $ticket->id])
        ->test(CreateMaintenanceRequest::class)
        ->assertSet('data.ticket_id', $ticket->id)
        ->assertSet('data.customer_id', $ticket->customer_id)
        ->assertSet('data.description', 'Pre-filled from the ticket')
        ->call('create')
        ->assertHasNoFormErrors();

    $record = MaintenanceRecord::query()->where('ticket_id', $ticket->id)->firstOrFail();

    expect($record->customer_id)->toBe($ticket->customer_id)
        ->and($record->description)->toBe('Pre-filled from the ticket');
});

it('raises a standalone maintenance request through the actual Create form when no ticket_id is present', function (): void {
    $manager = makeMaintenanceSupportManager();
    $customer = CustomerProfile::factory()->create();

    Livewire::actingAs($manager)
        ->test(CreateMaintenanceRequest::class)
        ->assertSet('data.ticket_id', null)
        ->fillForm([
            'customer_id' => $customer->id,
            'description' => 'Standalone via the form',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $record = MaintenanceRecord::query()->where('description', 'Standalone via the form')->firstOrFail();

    expect($record->ticket_id)->toBeNull()
        ->and($record->customer_id)->toBe($customer->id);
});

it('requires a customer and description for a standalone request, with no ticket_id', function (): void {
    $manager = makeMaintenanceSupportManager();
    $customer = CustomerProfile::factory()->create();

    $record = app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->id,
        'description' => 'Standalone repair request',
        'warranty_status' => WarrantyStatus::Unknown->value,
    ], $manager);

    expect($record->ticket_id)->toBeNull()
        ->and($record->customer_id)->toBe($customer->id);
});

it('defaults to unknown warranty when given a malformed, non-string, non-enum warranty_status', function (): void {
    $manager = makeMaintenanceSupportManager();
    $customer = CustomerProfile::factory()->create();

    $record = app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->id,
        'description' => 'Malformed warranty input',
        'warranty_status' => 12345,
    ], $manager);

    expect($record->warranty_status)->toBe(WarrantyStatus::Unknown);
});

it('links a matching serial number to its equipment unit and shows the product variant, but saves a non-matching one as free text, unlinked', function (): void {
    $manager = makeMaintenanceSupportManager();
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create(['serial_number' => 'SER-MATCHED-001']);

    $linked = app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->id,
        'description' => 'Repair with known equipment',
        'serial_number' => 'SER-MATCHED-001',
        'warranty_status' => WarrantyStatus::Unknown->value,
    ], $manager);

    expect($linked->serialized_inventory_unit_id)->toBe($unit->id)
        ->and($linked->product_variant_id)->toBe($unit->product_variant_id)
        ->and($linked->is_equipment_unlinked)->toBeFalse();

    $unlinked = app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->id,
        'description' => 'Repair with unknown equipment',
        'serial_number' => 'SER-NEVER-SEEN-999',
        'warranty_status' => WarrantyStatus::Unknown->value,
    ], $manager);

    expect($unlinked->serial_number)->toBe('SER-NEVER-SEEN-999')
        ->and($unlinked->serialized_inventory_unit_id)->toBeNull()
        ->and($unlinked->is_equipment_unlinked)->toBeTrue();

    $noSerialAtAll = app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->id,
        'description' => 'No equipment info given at all',
        'warranty_status' => WarrantyStatus::Unknown->value,
    ], $manager);

    expect($noSerialAtAll->is_equipment_unlinked)->toBeFalse();
});

it('matches a serial number case-insensitively and with surrounding whitespace trimmed', function (): void {
    $manager = makeMaintenanceSupportManager();
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create(['serial_number' => 'SER-MIXED-Case-001']);

    $record = app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->id,
        'description' => 'Repair with differently-cased serial',
        'serial_number' => '  ser-mixed-case-001  ',
        'warranty_status' => WarrantyStatus::Unknown->value,
    ], $manager);

    expect($record->serialized_inventory_unit_id)->toBe($unit->id)
        ->and($record->serial_number)->toBe('ser-mixed-case-001')
        ->and($record->is_equipment_unlinked)->toBeFalse();
});

it("refuses to link a serialized unit that is already in a different customer's custody", function (): void {
    $manager = makeMaintenanceSupportManager();
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'serial_number' => 'SER-OWNED-001',
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $otherCustomer->id,
    ]);

    expect(fn (): MaintenanceRecord => app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->id,
        'description' => 'Repair with equipment owned by someone else',
        'serial_number' => 'SER-OWNED-001',
        'warranty_status' => WarrantyStatus::Unknown->value,
    ], $manager))->toThrow(ValidationException::class, 'The selected equipment is not in this customer custody.');
});

it('rejects warranty_status covered without a warranty_expiry_date, at the service layer', function (): void {
    $manager = makeMaintenanceSupportManager();
    $customer = CustomerProfile::factory()->create();

    expect(fn () => app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->id,
        'description' => 'Covered but missing expiry',
        'warranty_status' => WarrantyStatus::Covered->value,
    ], $manager))->toThrow(ValidationException::class);

    $record = app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->id,
        'description' => 'Covered with expiry',
        'warranty_status' => WarrantyStatus::Covered->value,
        'warranty_expiry_date' => now()->addYear()->toDateString(),
    ], $manager);

    expect($record->warranty_status)->toBe(WarrantyStatus::Covered);
});

it('rejects warranty_status covered without an expiry date at the model level too, bypassing the service entirely', function (): void {
    $customer = CustomerProfile::factory()->create();

    expect(fn () => MaintenanceRecord::query()->create([
        'customer_id' => $customer->id,
        'description' => 'Direct model write bypassing MaintenanceRecordService',
        'warranty_status' => WarrantyStatus::Covered->value,
        'warranty_expiry_date' => null,
        'status' => MaintenanceStatus::Open->value,
    ]))->toThrow(DomainException::class);
});

it("keeps a maintenance request's equipment link intact when its serialized unit is disposed, and refuses to hard-delete a referenced unit", function (): void {
    $manager = makeMaintenanceSupportManager();
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create(['serial_number' => 'SER-DISPOSAL-001']);

    $record = app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->id,
        'description' => 'Repair on equipment later disposed',
        'serial_number' => 'SER-DISPOSAL-001',
        'warranty_status' => WarrantyStatus::Unknown->value,
    ], $manager);

    $unit->update(['status' => SerializedInventoryUnitStatus::Disposed]);

    expect($record->refresh()->serialized_inventory_unit_id)->toBe($unit->id);
    expect(fn () => $unit->forceDelete())->toThrow(QueryException::class);
    expect($record->refresh()->serialized_inventory_unit_id)->toBe($unit->id);
});

it('permits only open->in_progress|cancelled and in_progress->closed|cancelled, rejecting everything else including a direct service call', function (): void {
    $manager = makeMaintenanceSupportManager();
    $service = app(MaintenanceRecordService::class);

    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);
    expect(fn () => $service->transition($record, MaintenanceStatus::Closed, $manager))
        ->toThrow(InvalidStatusTransition::class);

    $service->transition($record, MaintenanceStatus::InProgress, $manager);
    expect($record->refresh()->status)->toBe(MaintenanceStatus::InProgress);

    $service->transition($record, MaintenanceStatus::Closed, $manager);
    expect($record->refresh()->status)->toBe(MaintenanceStatus::Closed);

    expect(fn () => $service->transition($record, MaintenanceStatus::InProgress, $manager))
        ->toThrow(InvalidStatusTransition::class);
});

it('archives a maintenance request on delete rather than removing it, keeping its service records intact', function (): void {
    $record = MaintenanceRecord::factory()->create();
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create();

    $record->delete();

    expect(MaintenanceRecord::query()->count())->toBe(0)
        ->and(MaintenanceRecord::withTrashed()->whereKey($record->id)->exists())->toBeTrue()
        ->and(MaintenanceTask::query()->whereKey($task->id)->exists())->toBeTrue()
        ->and($task->refresh()->maintenance_record_id)->toBe($record->id);
});

it('rejects closing a maintenance request while one of its service records is non-terminal, even directly (FR-066)', function (): void {
    $manager = makeMaintenanceSupportManager();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::InProgress]);
    MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::Open]);

    expect(fn () => app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::Closed, $manager))
        ->toThrow(InvalidStatusTransition::class);

    expect($record->refresh()->status)->toBe(MaintenanceStatus::InProgress);
});

it('notifies rather than crashing when the actual close table action hits the FR-066 guard', function (): void {
    $manager = makeMaintenanceSupportManager();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::InProgress]);
    MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::Open]);

    Livewire::actingAs($manager)
        ->test(ListMaintenanceRequests::class)
        ->callTableAction('close', $record)
        ->assertNotified();

    expect($record->refresh()->status)->toBe(MaintenanceStatus::InProgress);
});

it('allows closing a maintenance request once every service record has reached a terminal status', function (): void {
    $manager = makeMaintenanceSupportManager();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::InProgress]);
    MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::Closed]);
    MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::Cancelled]);

    app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::Closed, $manager);

    expect($record->refresh()->status)->toBe(MaintenanceStatus::Closed);
});

it('keeps the equipment link and serial number intact after the unit is disposed or adjusted out', function (): void {
    $manager = makeMaintenanceSupportManager();
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create(['serial_number' => 'SER-SURVIVOR-001']);

    $record = app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->id,
        'description' => 'Repair before disposal',
        'serial_number' => 'SER-SURVIVOR-001',
        'warranty_status' => WarrantyStatus::Unknown->value,
    ], $manager);

    $unit->update(['status' => SerializedInventoryUnitStatus::Disposed]);

    expect($record->refresh()->serialized_inventory_unit_id)->toBe($unit->id)
        ->and($record->serial_number)->toBe('SER-SURVIVOR-001');
});

it('grants view/manage to Support Manager, view-only to Support Agent, denying manage in every layer', function (): void {
    $manager = makeMaintenanceSupportManager();
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $policy = app(MaintenanceRecordPolicy::class);

    expect($policy->viewAny($manager))->toBeTrue()
        ->and($policy->create($manager))->toBeTrue()
        ->and($policy->viewAny($agent))->toBeTrue()
        ->and($policy->create($agent))->toBeFalse()
        ->and($policy->deleteAny($agent))->toBeFalse();

    $record = MaintenanceRecord::factory()->create();

    Livewire::actingAs($manager)->test(ListMaintenanceRequests::class)->assertTableActionVisible('archive', $record);
    Livewire::actingAs($agent)->test(ListMaintenanceRequests::class)->assertTableActionHidden('archive', $record);

    expect(fn () => app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::InProgress, $agent))
        ->toThrow(AuthorizationException::class);
});

it('loads and saves the actual Edit form for a covered-warranty record without exposing routine warranty editing', function (): void {
    $manager = makeMaintenanceSupportManager();
    $record = MaintenanceRecord::factory()->covered()->create();

    Livewire::actingAs($manager)
        ->test(EditMaintenanceRequest::class, ['record' => $record->getRouteKey()])
        ->fillForm(['description' => 'Updated via the edit form'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->refresh()->description)->toBe('Updated via the edit form')
        ->and($record->warranty_status)->toBe(WarrantyStatus::Covered);
});

it('transitions and archives a maintenance request through the actual table row actions', function (): void {
    $manager = makeMaintenanceSupportManager();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);

    $list = Livewire::actingAs($manager)->test(ListMaintenanceRequests::class);
    $list->callTableAction('startProgress', $record);

    expect($record->refresh()->status)->toBe(MaintenanceStatus::InProgress);

    $list->callTableAction('archive', $record);
    expect($record->refresh()->trashed())->toBeTrue();
});

it('denies restoring an archived maintenance request to a Support Manager via the row action', function (): void {
    $manager = makeMaintenanceSupportManager();
    $record = MaintenanceRecord::factory()->create();
    $record->delete();

    Livewire::actingAs($manager)
        ->test(ListMaintenanceRequests::class)
        ->filterTable('trashed', true)
        ->assertTableActionHidden('restore', $record);
});

it('restores a single archived maintenance request via the row action only for System Admin', function (): void {
    $admin = makeMaintenanceSystemAdmin();
    $record = MaintenanceRecord::factory()->create();
    $record->delete();

    Livewire::actingAs($admin)
        ->test(ListMaintenanceRequests::class)
        ->filterTable('trashed', true)
        ->callTableAction('restore', $record);

    expect($record->refresh()->trashed())->toBeFalse();
});

it('bulk-archives maintenance requests but denies bulk-restore to a Support Manager', function (): void {
    $manager = makeMaintenanceSupportManager();
    $first = MaintenanceRecord::factory()->create();
    $second = MaintenanceRecord::factory()->create();

    Livewire::actingAs($manager)
        ->test(ListMaintenanceRequests::class)
        ->callTableBulkAction('archive', [$first, $second]);

    expect($first->refresh()->trashed())->toBeTrue()
        ->and($second->refresh()->trashed())->toBeTrue();

    Livewire::actingAs($manager)
        ->test(ListMaintenanceRequests::class)
        ->filterTable('trashed', true)
        ->assertTableBulkActionHidden('restore');
});

it('bulk-restores maintenance requests through the actual toolbar action only for System Admin', function (): void {
    $admin = makeMaintenanceSystemAdmin();
    $first = MaintenanceRecord::factory()->create();
    $second = MaintenanceRecord::factory()->create();
    $first->delete();
    $second->delete();

    Livewire::actingAs($admin)
        ->test(ListMaintenanceRequests::class)
        ->filterTable('trashed', true)
        ->callTableBulkAction('restore', [$first, $second]);

    expect($first->refresh()->trashed())->toBeFalse()
        ->and($second->refresh()->trashed())->toBeFalse();
});

it('covers sold-by-us ticket equipment and standalone equipment id resolution branches', function (): void {
    $manager = makeMaintenanceSupportManager();
    $customer = CustomerProfile::factory()->create();
    $firstUnit = SerializedInventoryUnit::factory()->create([
        'serial_number' => 'SER-MAINT-ID-001',
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
    ]);
    $secondUnit = SerializedInventoryUnit::factory()->create([
        'serial_number' => 'SER-MAINT-ID-002',
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
    ]);

    $ticket = Ticket::factory()->for($customer, 'customer')->create([
        'equipment_source' => TicketEquipmentSource::SoldByUs,
        'serialized_inventory_unit_id' => $firstUnit->getKey(),
        'warranty_status' => WarrantyStatus::Unknown,
        'service_path' => TicketServicePath::Maintenance,
        'triaged_at' => now(),
        'status' => TicketStatus::Live,
    ]);

    $fromTicket = app(MaintenanceRecordService::class)->createFromTicket($ticket, [
        'description' => 'Sold-by-us maintenance coverage',
    ], $manager);

    expect($fromTicket->serial_number)->toBe('SER-MAINT-ID-001')
        ->and($fromTicket->serialized_inventory_unit_id)->toBe($firstUnit->getKey());

    $standalone = app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->getKey(),
        'description' => 'Standalone selected equipment',
        'serialized_inventory_unit_id' => $firstUnit->getKey(),
    ], $manager);

    expect($standalone->serialized_inventory_unit_id)->toBe($firstUnit->getKey())
        ->and($standalone->serial_number)->toBe('SER-MAINT-ID-001');

    app(MaintenanceRecordService::class)->update($standalone, [
        'serialized_inventory_unit_id' => (string) $secondUnit->getKey(),
    ], $manager);

    expect($standalone->refresh()->serialized_inventory_unit_id)->toBe($secondUnit->getKey())
        ->and($standalone->serial_number)->toBe('SER-MAINT-ID-002');

    app(MaintenanceRecordService::class)->update($standalone, [
        'serialized_inventory_unit_id' => [],
    ], $manager);

    expect($standalone->refresh()->serialized_inventory_unit_id)->toBe($secondUnit->getKey());

    app(MaintenanceRecordService::class)->update($standalone, [
        'customer_id' => [],
    ], $manager);

    expect($standalone->refresh()->customer_id)->toBe($customer->getKey())
        ->and($standalone->serialized_inventory_unit_id)->toBe($secondUnit->getKey());
});

it('covers invalid selected equipment and implicit external warranty resolution', function (): void {
    $manager = makeMaintenanceSupportManager();
    $customer = CustomerProfile::factory()->create();

    expect(fn (): MaintenanceRecord => app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->getKey(),
        'description' => 'Missing selected unit',
        'serialized_inventory_unit_id' => 999999999,
    ], $manager))->toThrow(ValidationException::class, 'selected equipment could not be found');

    $external = app(MaintenanceRecordService::class)->createStandalone([
        'customer_id' => $customer->getKey(),
        'description' => 'Unlinked external equipment without explicit warranty',
        'serial_number' => 'EXT-MAINT-COVERAGE-001',
    ], $manager);

    expect($external->is_equipment_unlinked)->toBeTrue()
        ->and($external->warranty_status)->toBe(WarrantyStatus::NotApplicable);
});
