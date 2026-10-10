<?php

declare(strict_types=1);

use App\Enums\TicketEquipmentSource;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\User;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketTriageService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
    configurePaymentAccounting();
});

it('preserves documented no-fee intake to resolution and closure with auditable assignment', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $profile = EmployeeProfile::factory()->create(['user_id' => $agent->id]);
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    $lifecycle = app(TicketLifecycleService::class);
    expect(fn () => $lifecycle->transition($ticket, TicketStatus::InProgress, $manager))
        ->toThrow(InvalidStatusTransition::class);

    app(TicketTriageService::class)->triage($ticket, [
        'equipment_source' => TicketEquipmentSource::External->value,
        'external_equipment_name' => 'Isolated QA device',
        'service_path' => TicketServicePath::RemoteSupport->value,
        'billing_decision' => 'no_charge',
    ], $manager);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Live);

    $lifecycle->assign($ticket, $profile, $manager);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Assigned)
        ->and($ticket->assigned_employee_id)->toBe($profile->id)
        ->and(TicketAssignment::query()->where('ticket_id', $ticket->id)->count())->toBe(1);

    $lifecycle->transition($ticket, TicketStatus::InProgress, $agent);
    $lifecycle->transition($ticket, TicketStatus::WaitingCustomer, $agent);

    expect($ticket->refresh()->status)->toBe(TicketStatus::WaitingCustomer);
    $lifecycle->transition($ticket, TicketStatus::InProgress, $agent);
    expect($ticket->refresh()->status)->toBe(TicketStatus::InProgress);

    expect(fn () => $lifecycle->transition($ticket, TicketStatus::Resolved, $agent))
        ->toThrow(ValidationException::class);
    expect($ticket->refresh()->status)->toBe(TicketStatus::InProgress);

    $lifecycle->transition($ticket, TicketStatus::Resolved, $agent, 'QA verified the remote workaround.');
    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->resolved_at)->not->toBeNull()
        ->and($ticket->resolution_summary)->toBe('QA verified the remote workaround.');

    $lifecycle->transition($ticket, TicketStatus::Closed, $manager);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
    expect(fn () => $lifecycle->transition($ticket, TicketStatus::InProgress, $manager))
        ->toThrow(InvalidStatusTransition::class);
});

it('keeps own-ticket work permission separate from manager assignment and settlement', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $outsider = User::factory()->admin()->create();
    $outsider->assignRole('Support Agent');

    $employee = EmployeeProfile::factory()->create(['user_id' => $agent->id]);
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Assigned,
        'assigned_employee_id' => $employee->id,
    ]);

    expect(Gate::forUser($agent)->allows('work', $ticket))->toBeTrue()
        ->and(Gate::forUser($outsider)->allows('work', $ticket))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('settlePayment', $ticket))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('assign', $ticket))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('restore', $ticket))->toBeFalse();
});
