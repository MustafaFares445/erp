<?php

declare(strict_types=1);

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\TicketBlocker;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Models\MaintenanceRecord;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\MaintenanceBillingService;
use App\Services\Support\TicketBlockerResolver;
use App\Services\Support\TicketPaymentService;
use App\Services\Support\TicketTriageService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
    $this->paymentMethod107 = configurePaymentAccounting(taxPercent: 5.0);
});

function coverage107Manager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

it('queries maintenance records when no active-maintenance projection is present', function (): void {
    $ticket = Ticket::factory()->triagedForMaintenance()->create([
        'status' => TicketStatus::InProgress,
        'assigned_employee_id' => null,
    ]);

    expect(app(TicketBlockerResolver::class)->resolve($ticket))
        ->toBe(TicketBlocker::MaintenanceAction);
});

it('accepts already-resolved ticket triage enums', function (): void {
    $service = app(TicketTriageService::class);

    $equipmentSource = new ReflectionMethod(TicketTriageService::class, 'equipmentSource');
    $servicePath = new ReflectionMethod(TicketTriageService::class, 'servicePath');

    expect($equipmentSource->invoke($service, TicketEquipmentSource::External))
        ->toBe(TicketEquipmentSource::External)
        ->and($servicePath->invoke($service, TicketServicePath::Maintenance))
        ->toBe(TicketServicePath::Maintenance);
});

it('rejects ticket-settled billing when the linked payment lost its posted accounting state', function (): void {
    $manager = coverage107Manager();
    $settler = User::factory()->admin()->create();
    $ticket = Ticket::factory()->chargeable()->create();
    $link = app(TicketPaymentService::class)->createForTicket($ticket, 105.00, 'AED');

    app(TicketPaymentService::class)->settle(
        $link,
        'REF-UNPOSTED-COVERAGE',
        $settler,
        $this->paymentMethod107->getKey(),
    );

    $payment = $link->refresh()->payment;
    expect($payment)->not->toBeNull();

    $payment->forceFill(['posted_at' => null])->saveQuietly();

    $record = MaintenanceRecord::factory()->for($ticket)->create([
        'customer_id' => $ticket->customer_id,
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    expect(fn () => app(MaintenanceBillingService::class)
        ->markTicketSettled($record, $manager, 'Coverage for unposted payment guard.'))
        ->toThrow(ValidationException::class);
});

it('rejects ticket-settled billing when the settled deposit cannot fully cover the invoice', function (): void {
    $manager = coverage107Manager();
    $settler = User::factory()->admin()->create();
    $ticket = Ticket::factory()->chargeable()->create();
    $link = app(TicketPaymentService::class)->createForTicket($ticket, 105.00, 'AED');

    app(TicketPaymentService::class)->settle(
        $link,
        'REF-SHORT-DEPOSIT-COVERAGE',
        $settler,
        $this->paymentMethod107->getKey(),
    );

    $link->refresh()->update(['amount' => '150.00']);

    $record = MaintenanceRecord::factory()->for($ticket)->create([
        'customer_id' => $ticket->customer_id,
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    expect(fn () => app(MaintenanceBillingService::class)
        ->markTicketSettled($record, $manager, 'Coverage for short deposit guard.'))
        ->toThrow(ValidationException::class);
});
