<?php

declare(strict_types=1);

use App\Enums\SerializedCustodyType;
use App\Enums\SupportAssignmentStrategy;
use App\Enums\TicketCustomerImpact;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\SupportRoutingRule;
use App\Models\SupportTeam;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Services\Support\TicketAttachmentSynchronizer;
use App\Services\Support\TicketIntakeService;
use App\Services\Support\TicketPaymentService;
use App\Services\Support\TicketTriageService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

function ticketServicesRoutingTeam(): SupportTeam
{
    $team = SupportTeam::query()->create([
        'code' => 'COV',
        'name' => 'Coverage team',
        'assignment_strategy' => SupportAssignmentStrategy::LeastLoaded,
        'is_active' => true,
    ]);

    SupportRoutingRule::query()->create([
        'name' => 'Catch all',
        'precedence' => 1,
        'is_active' => true,
        'support_team_id' => $team->getKey(),
        'auto_assign' => false,
    ]);

    return $team;
}

it('creates a customer ticket from enum-typed type and impact values and derives the priority', function (): void {
    $customer = CustomerProfile::factory()->create();

    $ticket = app(TicketIntakeService::class)->createForCustomer([
        'type' => TicketType::HardwareIssue,
        'customer_impact' => TicketCustomerImpact::Degraded,
        'title' => 'Printer degraded',
        'description' => 'Prints are faint.',
    ], $customer->user);

    expect($ticket->customer_id)->toBe($customer->getKey())
        ->and($ticket->type)->toBe(TicketType::HardwareIssue)
        ->and($ticket->customer_impact)->toBe(TicketCustomerImpact::Degraded)
        ->and($ticket->priority)->toBe(TicketPriority::High)
        ->and($ticket->status)->toBe(TicketStatus::Pending)
        ->and($ticket->equipment_source)->toBeNull();
});

it('links a customer ticket to owned equipment and rejects equipment owned by someone else', function (): void {
    $customer = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();

    $owned = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->getKey(),
    ]);
    $foreign = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $other->getKey(),
    ]);

    $ticket = app(TicketIntakeService::class)->createForCustomer([
        'type' => TicketType::HardwareIssue->value,
        'title' => 'Owned unit fault',
        'description' => 'Unit will not start.',
        'serialized_inventory_unit_id' => $owned->getKey(),
        'external_equipment_name' => 'Ignored when a unit is selected',
    ], $customer->user);

    expect($ticket->equipment_source)->toBe(TicketEquipmentSource::SoldByUs)
        ->and($ticket->serialized_inventory_unit_id)->toBe($owned->getKey())
        ->and($ticket->external_equipment_name)->toBeNull();

    expect(fn (): Ticket => app(TicketIntakeService::class)->createForCustomer([
        'type' => TicketType::HardwareIssue->value,
        'title' => 'Foreign unit fault',
        'description' => 'Not mine.',
        'serialized_inventory_unit_id' => $foreign->getKey(),
    ], $customer->user))->toThrow(ValidationException::class);
});

it('routes the ticket to the matching team right after triage when smart routing is enabled', function (): void {
    config()->set('support.smart_routing_enabled', true);

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $team = ticketServicesRoutingTeam();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    app(TicketTriageService::class)->triage($ticket, [
        'equipment_source' => TicketEquipmentSource::External->value,
        'external_equipment_name' => 'External pump',
        'service_path' => TicketServicePath::RemoteSupport->value,
        'billing_decision' => 'no_charge',
    ], $manager);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Live)
        ->and($ticket->support_team_id)->toBe($team->getKey())
        ->and($ticket->routed_at)->not->toBeNull();
});

it('routes the ticket to the matching team right after the diagnostic fee is settled when smart routing is enabled', function (): void {
    config()->set('support.smart_routing_enabled', true);
    configurePaymentAccounting();

    $admin = User::factory()->admin()->create();
    $team = ticketServicesRoutingTeam();
    $ticket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($ticket)->create();

    app(TicketPaymentService::class)->settle($link, 'REF-ROUTE-1', $admin);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Live)
        ->and($ticket->support_team_id)->toBe($team->getKey());
});

it('rejects a customer-app attachment larger than the maximum allowed size', function (): void {
    Storage::fake('local');
    $ticket = Ticket::factory()->create();

    $oversized = UploadedFile::fake()->create('too-big.pdf', 10 * 1024 + 1, 'application/pdf');

    expect(fn () => app(TicketAttachmentSynchronizer::class)->addUploadedFiles($ticket, [$oversized]))
        ->toThrow(ValidationException::class, 'may not be greater than 10 MB');

    expect($ticket->fresh()->getMedia('ticket-attachments'))->toHaveCount(0);
});
