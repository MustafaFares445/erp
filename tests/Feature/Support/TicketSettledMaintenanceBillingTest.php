<?php

declare(strict_types=1);

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Services\Support\MaintenanceBillingService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

function ticketSettledBillingManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

it('marks a closed maintenance request as covered by its settled ticket payment', function (): void {
    $manager = ticketSettledBillingManager();
    $ticket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($ticket)->settled()->create();
    $record = MaintenanceRecord::factory()->for($ticket)->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    app(MaintenanceBillingService::class)->markTicketSettled($record, $manager, 'Support fee already collected on ticket.');

    expect($record->refresh()->billing_type)->toBe(MaintenanceBillingType::TicketSettled)
        ->and($record->billed_at)->not->toBeNull()
        ->and($record->ticket?->paymentLink?->is($link))->toBeTrue();
});

it('rejects ticket-settled billing when the linked ticket payment is not settled', function (): void {
    $manager = ticketSettledBillingManager();
    $ticket = Ticket::factory()->chargeable()->create();
    TicketPaymentLink::factory()->for($ticket)->create();
    $record = MaintenanceRecord::factory()->for($ticket)->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    expect(fn () => app(MaintenanceBillingService::class)->markTicketSettled($record, $manager, 'Attempt before settlement.'))
        ->toThrow(ValidationException::class);

    expect($record->refresh()->billing_type)->toBe(MaintenanceBillingType::Unbilled)
        ->and($record->billed_at)->toBeNull();
});
