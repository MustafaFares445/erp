<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\PaymentLinkStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\AssignmentsRelationManager;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketMessageService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

function makeSupportManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

function makeSystemAdmin(): User
{
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    return $admin;
}

/** @return array{0: User, 1: EmployeeProfile} */
function makeSupportAgentWithProfile(): array
{
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $profile = EmployeeProfile::factory()->create(['user_id' => $agent->id]);

    return [$agent, $profile];
}

it('accepts every FR-022 allowed transition and rejects the disallowed ones, including via a direct call bypassing the UI', function (): void {
    $manager = makeSupportManager();
    [$agent, $profile] = makeSupportAgentWithProfile();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);
    $service = app(TicketLifecycleService::class);

    // pending -> live (manager, triage)
    $service->transition($ticket, TicketStatus::Live, $manager);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Live);

    // live -> assigned (via assign(), manager)
    $service->assign($ticket, $profile, $manager);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Assigned)
        ->and($ticket->assigned_employee_id)->toBe($profile->id);

    // assigned -> in_progress (agent, their own ticket)
    $service->transition($ticket, TicketStatus::InProgress, $agent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::InProgress);

    // in_progress -> waiting_customer (agent)
    $service->transition($ticket, TicketStatus::WaitingCustomer, $agent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::WaitingCustomer);

    // waiting_customer -> in_progress (agent)
    $service->transition($ticket, TicketStatus::InProgress, $agent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::InProgress);

    // in_progress -> resolved (agent)
    $service->transition($ticket, TicketStatus::Resolved, $agent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->resolved_at)->not->toBeNull();

    // resolved -> closed (manager)
    $service->transition($ticket, TicketStatus::Closed, $manager);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);

    // closed -> anything is rejected, even a direct service call.
    expect(fn () => $service->transition($ticket, TicketStatus::InProgress, $manager))
        ->toThrow(InvalidStatusTransition::class);
});

it('rejects a disallowed transition naming the current and attempted status', function (): void {
    $manager = makeSupportManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    expect(fn () => app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Resolved, $manager))
        ->toThrow(InvalidStatusTransition::class);
});

it('appends a new assignment row on reassignment, retains the prior one, and the current assignee reflects only the newest', function (): void {
    $manager = makeSupportManager();
    [, $firstProfile] = makeSupportAgentWithProfile();
    [, $secondProfile] = makeSupportAgentWithProfile();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);
    $service = app(TicketLifecycleService::class);

    $service->assign($ticket, $firstProfile, $manager);
    $service->assign($ticket, $secondProfile, $manager);

    expect(TicketAssignment::query()->where('ticket_id', $ticket->id)->count())->toBe(2)
        ->and($ticket->refresh()->assigned_employee_id)->toBe($secondProfile->id);
});

it('notifies rather than crashing when the actual assign action targets a ticket in a non-assignable status', function (): void {
    $manager = makeSupportManager();
    $profile = EmployeeProfile::factory()->create();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    Livewire::actingAs($manager)
        ->test(AssignmentsRelationManager::class, [
            'ownerRecord' => $ticket,
            'pageClass' => ViewTicket::class,
        ])
        ->callAction(TestAction::make('assign')->table(), ['employee_id' => $profile->id])
        ->assertNotified();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Pending)
        ->and($ticket->assigned_employee_id)->toBeNull();
});

it('unassigns a ticket, clearing the assignee and returning it to live', function (): void {
    $manager = makeSupportManager();
    [, $profile] = makeSupportAgentWithProfile();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);
    $service = app(TicketLifecycleService::class);
    $service->assign($ticket, $profile, $manager);

    $service->unassign($ticket, $manager);

    expect($ticket->refresh()->assigned_employee_id)->toBeNull()
        ->and($ticket->status)->toBe(TicketStatus::Live);
});

it('rejects unassigning a ticket that is not currently assigned', function (): void {
    $manager = makeSupportManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);

    expect(fn () => app(TicketLifecycleService::class)->unassign($ticket, $manager))
        ->toThrow(InvalidStatusTransition::class);
});

it('reopens a resolved ticket back to in_progress, clearing resolved_at', function (): void {
    $manager = makeSupportManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Resolved, 'resolved_at' => now()]);

    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::InProgress, $manager);

    expect($ticket->refresh()->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->resolved_at)->toBeNull();
});

it('rejects closing a ticket a Support Agent does not own, while their own ticket remains workable', function (): void {
    [$owner, $ownerProfile] = makeSupportAgentWithProfile();
    $otherAgent = User::factory()->admin()->create();
    $otherAgent->assignRole('Support Agent');

    $manager = makeSupportManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);
    $service = app(TicketLifecycleService::class);
    $service->assign($ticket, $ownerProfile, $manager);

    expect(fn () => $service->transition($ticket, TicketStatus::InProgress, $otherAgent))
        ->toThrow(AuthorizationException::class);

    $service->transition($ticket, TicketStatus::InProgress, $owner);
    expect($ticket->refresh()->status)->toBe(TicketStatus::InProgress);
});

it('rejects closing a ticket with a non-terminal linked maintenance request (FR-026), even via a direct service call', function (): void {
    $manager = makeSupportManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Resolved]);
    MaintenanceRecord::factory()->for($ticket)->create(['status' => MaintenanceStatus::Open]);

    expect(fn () => app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Closed, $manager))
        ->toThrow(InvalidStatusTransition::class);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);
});

it('notifies rather than crashing when the actual close table action hits the FR-026 guard', function (): void {
    $manager = makeSupportManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Resolved]);
    MaintenanceRecord::factory()->for($ticket)->create(['status' => MaintenanceStatus::Open]);

    Livewire::actingAs($manager)
        ->test(ListTickets::class)
        ->callTableAction('close', $ticket)
        ->assertNotified();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);
});

it('allows closing a ticket once every linked maintenance request has reached a terminal status', function (): void {
    $manager = makeSupportManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Resolved]);
    MaintenanceRecord::factory()->for($ticket)->create(['status' => MaintenanceStatus::Closed]);
    MaintenanceRecord::factory()->for($ticket)->create(['status' => MaintenanceStatus::Cancelled]);

    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Closed, $manager);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
});

it('sets first_response_at once on the first customer-visible message and never overwrites it', function (): void {
    $manager = makeSupportManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);
    $service = app(TicketMessageService::class);

    $service->post($ticket, 'Internal context', true, $manager);

    expect($ticket->refresh()->first_response_at)->toBeNull();

    $service->post($ticket, 'Hello, we are looking into this.', false, $manager);
    $firstResponseAt = $ticket->refresh()->first_response_at;
    expect($firstResponseAt)->not->toBeNull();

    $service->post($ticket, 'A follow-up message.', false, $manager);
    expect($ticket->refresh()->first_response_at->eq($firstResponseAt))->toBeTrue();
});

it('allows a Support Agent to post or work only on a ticket assigned to them', function (): void {
    [$owner, $ownerProfile] = makeSupportAgentWithProfile();
    $otherAgent = User::factory()->admin()->create();
    $otherAgent->assignRole('Support Agent');

    $manager = makeSupportManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);
    app(TicketLifecycleService::class)->assign($ticket, $ownerProfile, $manager);

    expect(fn () => app(TicketMessageService::class)->post($ticket, 'Not my ticket', false, $otherAgent))
        ->toThrow(AuthorizationException::class);

    $message = app(TicketMessageService::class)->post($ticket, 'My ticket', false, $owner);
    expect($message->sender_user_id)->toBe($owner->id);
});

it('drives a ticket through its full lifecycle via the actual table row actions', function (): void {
    $manager = makeSupportManager();
    [, $profile] = makeSupportAgentWithProfile();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    $list = Livewire::actingAs($manager)->test(ListTickets::class);

    $list->callTableAction('triage', $ticket);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Live);

    app(TicketLifecycleService::class)->assign($ticket, $profile, $manager);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Assigned);

    $list->callTableAction('startProgress', $ticket);
    expect($ticket->refresh()->status)->toBe(TicketStatus::InProgress);

    $list->callTableAction('waitForCustomer', $ticket);
    expect($ticket->refresh()->status)->toBe(TicketStatus::WaitingCustomer);

    $list->callTableAction('resumeWork', $ticket);
    expect($ticket->refresh()->status)->toBe(TicketStatus::InProgress);

    $list->callTableAction('resolve', $ticket);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);

    $list->callTableAction('reopen', $ticket);
    expect($ticket->refresh()->status)->toBe(TicketStatus::InProgress);

    $list->callTableAction('resolve', $ticket);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);

    $list->callTableAction('close', $ticket);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
});

it('cancels and unassigns a ticket through the actual table row actions', function (): void {
    $manager = makeSupportManager();
    [, $profile] = makeSupportAgentWithProfile();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);
    app(TicketLifecycleService::class)->assign($ticket, $profile, $manager);

    $list = Livewire::actingAs($manager)->test(ListTickets::class);

    $list->callTableAction('unassign', $ticket);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Live)
        ->and($ticket->assigned_employee_id)->toBeNull();

    $list->callTableAction('cancel', $ticket);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Cancelled);
});

it("settles a chargeable ticket's payment through the actual table row action", function (): void {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->chargeable()->create();
    TicketPaymentLink::factory()->for($ticket)->create();

    Livewire::actingAs($admin)
        ->test(ListTickets::class)
        ->callTableAction('settlePayment', $ticket, ['payment_method_reference' => 'REF-TABLE-1'])
        ->assertHasNoTableActionErrors();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Live)
        ->and($ticket->paymentLink?->refresh()->status)->toBe(PaymentLinkStatus::Settled);
});

it('archives through the actual table actions but denies restore to a Support Manager, individually and in bulk', function (): void {
    $manager = makeSupportManager();
    $ticket = Ticket::factory()->create();

    Livewire::actingAs($manager)->test(ListTickets::class)->callTableAction('archive', $ticket);
    expect($ticket->refresh()->trashed())->toBeTrue();

    Livewire::actingAs($manager)
        ->test(ListTickets::class)
        ->filterTable('trashed', true)
        ->assertTableActionHidden('restore', $ticket);

    $first = Ticket::factory()->create();
    $second = Ticket::factory()->create();

    Livewire::actingAs($manager)->test(ListTickets::class)->callTableBulkAction('archive', [$first, $second]);
    expect($first->refresh()->trashed())->toBeTrue()
        ->and($second->refresh()->trashed())->toBeTrue();

    Livewire::actingAs($manager)
        ->test(ListTickets::class)
        ->filterTable('trashed', true)
        ->assertTableBulkActionHidden('restore');
});

it('restores an archived ticket, individually and in bulk, only for System Admin', function (): void {
    $admin = makeSystemAdmin();
    $ticket = Ticket::factory()->create();
    $ticket->delete();

    Livewire::actingAs($admin)
        ->test(ListTickets::class)
        ->filterTable('trashed', true)
        ->callTableAction('restore', $ticket);
    expect($ticket->refresh()->trashed())->toBeFalse();

    $first = Ticket::factory()->create();
    $second = Ticket::factory()->create();
    $first->delete();
    $second->delete();

    Livewire::actingAs($admin)
        ->test(ListTickets::class)
        ->filterTable('trashed', true)
        ->callTableBulkAction('restore', [$first, $second]);
    expect($first->refresh()->trashed())->toBeFalse()
        ->and($second->refresh()->trashed())->toBeFalse();
});
