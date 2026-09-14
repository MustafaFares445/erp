<?php

declare(strict_types=1);

use App\Enums\SerializedCustodyType;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketTriageService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

function triageManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

it('triages external equipment to live without starting payment and starts SLA only then', function (): void {
    $manager = triageManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    expect($ticket->live_at)->toBeNull();

    app(TicketTriageService::class)->triage($ticket, [
        'equipment_source' => TicketEquipmentSource::External->value,
        'external_equipment_name' => 'External compressor',
        'external_equipment_model' => 'CX-20',
        'external_serial_number' => 'EXT-001',
        'service_path' => TicketServicePath::Maintenance->value,
        'billing_decision' => 'no_charge',
    ], $manager);

    $ticket->refresh();

    expect($ticket->status)->toBe(TicketStatus::Live)
        ->and($ticket->equipment_source)->toBe(TicketEquipmentSource::External)
        ->and($ticket->warranty_status)->toBe(WarrantyStatus::NotApplicable)
        ->and($ticket->service_path)->toBe(TicketServicePath::Maintenance)
        ->and($ticket->triaged_at)->not->toBeNull()
        ->and($ticket->live_at)->not->toBeNull()
        ->and($ticket->paymentLink()->exists())->toBeFalse();
});

it('moves payment-required triage to pending_payment without starting SLA', function (): void {
    $manager = triageManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    app(TicketTriageService::class)->triage($ticket, [
        'equipment_source' => TicketEquipmentSource::External->value,
        'external_equipment_name' => 'Third-party controller',
        'service_path' => TicketServicePath::RemoteSupport->value,
        'billing_decision' => 'payment_required',
        'amount' => 90,
        'currency' => 'USD',
    ], $manager);

    $ticket->refresh();

    expect($ticket->status)->toBe(TicketStatus::PendingPayment)
        ->and($ticket->is_chargeable)->toBeTrue()
        ->and($ticket->live_at)->toBeNull()
        ->and($ticket->paymentLink)->not->toBeNull()
        ->and((float) $ticket->paymentLink->amount)->toBe(90.0);
});

it('requires a waiver reason and rolls back the triage transaction if it is missing', function (): void {
    $manager = triageManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    expect(fn () => app(TicketTriageService::class)->triage($ticket, [
        'equipment_source' => TicketEquipmentSource::External->value,
        'external_equipment_name' => 'External device',
        'service_path' => TicketServicePath::RemoteSupport->value,
        'billing_decision' => 'waive',
    ], $manager))->toThrow(ValidationException::class);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Pending)
        ->and($ticket->triaged_at)->toBeNull();
});

it('accepts only serialized equipment currently in the ticket customer custody', function (): void {
    $manager = triageManager();
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $otherCustomer->id,
    ]);
    $ticket = Ticket::factory()->for($customer, 'customer')->create(['status' => TicketStatus::Pending]);

    expect(fn () => app(TicketTriageService::class)->triage($ticket, [
        'equipment_source' => TicketEquipmentSource::SoldByUs->value,
        'serialized_inventory_unit_id' => $unit->id,
        'service_path' => TicketServicePath::Maintenance->value,
        'billing_decision' => 'no_charge',
    ], $manager))->toThrow(ValidationException::class);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Pending)
        ->and($ticket->serialized_inventory_unit_id)->toBeNull();
});

it('refuses duplicate triage after the ticket has left pending', function (): void {
    $manager = triageManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);
    $data = [
        'equipment_source' => TicketEquipmentSource::External->value,
        'external_equipment_name' => 'External device',
        'service_path' => TicketServicePath::RemoteSupport->value,
        'billing_decision' => 'no_charge',
    ];

    app(TicketTriageService::class)->triage($ticket, $data, $manager);

    expect(fn () => app(TicketTriageService::class)->triage($ticket->refresh(), $data, $manager))
        ->toThrow(DomainException::class);
});
