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
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
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
