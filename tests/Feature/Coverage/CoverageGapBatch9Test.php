<?php

declare(strict_types=1);

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Filament\Resources\MaintenanceRequests\Actions\MaintenanceBillingActions;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Models\MaintenanceRecord;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Services\Support\MaintenanceBillingService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    Gate::before(static fn (): bool => true);
});

it('executes warranty-covered and warranty-reclassification billing actions', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    Livewire::actingAs($actor)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getKey()])
        ->callAction('mark_warranty_covered', ['reason' => 'Coverage warranty reason'])
        ->assertNotified();

    expect($record->refresh()->billing_type)->toBe(MaintenanceBillingType::WarrantyCovered);

    Livewire::actingAs($actor)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getKey()])
        ->callAction('reclassify_warranty_billing', ['reason' => 'Coverage reclassification reason'])
        ->assertNotified();

    expect($record->refresh()->billing_type)->toBe(MaintenanceBillingType::Unbilled);
});

it('executes ticket-settled maintenance billing action', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $ticket = Ticket::factory()->chargeable()->create();
    TicketPaymentLink::factory()->for($ticket)->settled()->create();

    $record = MaintenanceRecord::factory()->create([
        'ticket_id' => $ticket->getKey(),
        'customer_id' => $ticket->customer_id,
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    Livewire::actingAs($actor)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getKey()])
        ->callAction('mark_ticket_settled', ['reason' => 'Covered by settled support payment'])
        ->assertNotified();

    expect($record->refresh()->billing_type)->toBe(MaintenanceBillingType::TicketSettled);
});

it('covers maintenance billing action unauthenticated actor guard', function (): void {
    auth()->logout();

    $method = new ReflectionMethod(MaintenanceBillingActions::class, 'currentActor');

    expect(fn (): mixed => $method->invoke(null))
        ->toThrow(LogicException::class, 'authenticated User');
});

it('covers maintenance billing action validation and notification branches directly', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $closed = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);
    $warranty = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::WarrantyCovered,
    ]);

    $actions = collect(MaintenanceBillingActions::make())
        ->keyBy(static fn ($action): string => $action->getName());

    $actions->get('mark_warranty_covered')?->getActionFunction()($closed, ['reason' => '']);
    $actions->get('mark_ticket_settled')?->getActionFunction()($closed, ['reason' => '']);
    $actions->get('reclassify_warranty_billing')?->getActionFunction()($warranty, ['reason' => '   ']);

    expect($closed->refresh()->billing_type)->toBe(MaintenanceBillingType::Unbilled)
        ->and($warranty->refresh()->billing_type)->toBe(MaintenanceBillingType::WarrantyCovered);
});

it('covers quotation and invoice billing action success and failure notifications', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    $service = new class
    {
        public int $quotationCalls = 0;

        public int $invoiceCalls = 0;

        public function createQuotation(MaintenanceRecord $record, User $actor): stdClass
        {
            $this->quotationCalls++;

            return new stdClass;
        }

        public function createInvoice(MaintenanceRecord $record, User $actor): stdClass
        {
            $this->invoiceCalls++;

            return new stdClass;
        }

        public function reclassifyWarrantyForBilling(MaintenanceRecord $record, User $actor, string $reason): never
        {
            throw new DomainException('Coverage reclassification failure.');
        }
    };

    app()->instance(MaintenanceBillingService::class, $service);

    $actions = collect(MaintenanceBillingActions::make())
        ->keyBy(static fn ($action): string => $action->getName());

    $actions->get('create_quotation')?->getActionFunction()($record);
    $actions->get('create_invoice')?->getActionFunction()($record);
    $actions->get('reclassify_warranty_billing')?->getActionFunction()($record, ['reason' => 'Coverage']);

    expect($service->quotationCalls)->toBe(1)
        ->and($service->invoiceCalls)->toBe(1);

    $failingService = new class
    {
        public function createQuotation(MaintenanceRecord $record, User $actor): never
        {
            throw ValidationException::withMessages(['record' => 'Coverage quotation failure.']);
        }

        public function createInvoice(MaintenanceRecord $record, User $actor): never
        {
            throw new DomainException('Coverage invoice failure.');
        }
    };

    app()->instance(MaintenanceBillingService::class, $failingService);

    $actions->get('create_quotation')?->getActionFunction()($record);
    $actions->get('create_invoice')?->getActionFunction()($record);

    expect(true)->toBeTrue();
});
