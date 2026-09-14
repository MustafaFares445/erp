<?php

declare(strict_types=1);

use App\Enums\PaymentLinkStatus;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Policies\TicketPolicy;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use App\Services\Support\TicketIntakeService;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketPaymentService;
use App\Services\Support\TicketTriageService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

function makePaymentSupportManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

/** @return array<string, mixed> */
function chargeableTriageData(float $amount = 150.50, string $currency = 'USD'): array
{
    return [
        'equipment_source' => TicketEquipmentSource::External->value,
        'external_equipment_name' => 'External repair device',
        'service_path' => TicketServicePath::Maintenance->value,
        'billing_decision' => 'payment_required',
        'amount' => $amount,
        'currency' => $currency,
    ];
}

it('creates the pending payment link during triage rather than during intake', function (): void {
    $customer = CustomerProfile::factory()->create();
    $manager = makePaymentSupportManager();

    $ticket = app(TicketIntakeService::class)->create([
        'customer_id' => $customer->id,
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'Chargeable repair',
        'description' => 'Description',
    ], $manager);

    expect($ticket->status)->toBe(TicketStatus::Pending)
        ->and($ticket->paymentLink()->exists())->toBeFalse();

    app(TicketTriageService::class)->triage($ticket, chargeableTriageData(150.50, 'USD'), $manager);
    $ticket->refresh();
    $link = $ticket->paymentLink;

    expect($ticket->status)->toBe(TicketStatus::PendingPayment)
        ->and($ticket->pending_reason)->not->toBeNull()
        ->and($link)->not->toBeNull()
        ->and((float) $link->amount)->toBe(150.50)
        ->and($link->currency)->toBe('USD')
        ->and($link->status)->toBe(PaymentLinkStatus::Pending)
        ->and($ticket->live_at)->toBeNull();
});

it('rejects payment-required triage missing an amount or currency and rolls the ticket back to pending', function (): void {
    $customer = CustomerProfile::factory()->create();
    $manager = makePaymentSupportManager();
    $ticket = app(TicketIntakeService::class)->create([
        'customer_id' => $customer->id,
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'Missing amount',
        'description' => 'Description',
    ], $manager);

    $data = chargeableTriageData();
    unset($data['amount']);

    expect(fn () => app(TicketTriageService::class)->triage($ticket, $data, $manager))
        ->toThrow(ValidationException::class);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Pending)
        ->and($ticket->triaged_at)->toBeNull()
        ->and($ticket->paymentLink()->exists())->toBeFalse();
});

it('creates a normal ticket through the intake form then makes it chargeable through the actual triage row action', function (): void {
    $customer = CustomerProfile::factory()->create();
    $manager = makePaymentSupportManager();

    Livewire::actingAs($manager)
        ->test(CreateTicket::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'type' => TicketType::GeneralSupport->value,
            'priority' => TicketPriority::Normal->value,
            'title' => 'Chargeable after triage',
            'description' => 'Description',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $ticket = Ticket::query()->where('title', 'Chargeable after triage')->firstOrFail();
    expect($ticket->status)->toBe(TicketStatus::Pending);

    Livewire::actingAs($manager)
        ->test(ListTickets::class)
        ->callTableAction('triage', $ticket, chargeableTriageData(75, 'AED'))
        ->assertHasNoTableActionErrors();

    expect($ticket->refresh()->status)->toBe(TicketStatus::PendingPayment)
        ->and($ticket->paymentLink)->not->toBeNull()
        ->and((float) $ticket->paymentLink->amount)->toBe(75.0)
        ->and($ticket->paymentLink->currency)->toBe('AED');
});

it('rejects assignment or any transition on a pending_payment ticket other than settlement or cancellation', function (): void {
    $manager = makePaymentSupportManager();
    $profile = EmployeeProfile::factory()->create();
    $ticket = Ticket::factory()->chargeable()->create();

    expect(fn () => app(TicketLifecycleService::class)->assign($ticket, $profile, $manager))
        ->toThrow(InvalidStatusTransition::class);

    foreach ([TicketStatus::Live, TicketStatus::Assigned, TicketStatus::InProgress] as $target) {
        expect(fn () => app(TicketLifecycleService::class)->transition($ticket, $target, $manager))
            ->toThrow(InvalidStatusTransition::class);
    }
});

it('moves the link to settled and the ticket to live, clearing pending_reason, in one transaction', function (): void {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($ticket)->create();

    app(TicketPaymentService::class)->settle($link, 'REF-123', $admin);

    expect($link->refresh()->status)->toBe(PaymentLinkStatus::Settled)
        ->and($link->settled_by)->toBe($admin->id)
        ->and($link->settled_at)->not->toBeNull()
        ->and($link->payment_method_reference)->toBe('REF-123')
        ->and($ticket->refresh()->status)->toBe(TicketStatus::Live)
        ->and($ticket->pending_reason)->toBeNull();
});

it('rejects settling an already-settled link, leaving the ticket status unchanged', function (): void {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($ticket)->settled()->create();

    expect(fn () => app(TicketPaymentService::class)->settle($link, 'REF-456', $admin))
        ->toThrow(InvalidStatusTransition::class)
        ->and($ticket->refresh()->status)->toBe(TicketStatus::PendingPayment)
        ->and(TicketPaymentLink::query()->where('status', PaymentLinkStatus::Settled)->count())->toBe(1);

    $rejection = AuditLog::query()->where('description', 'support.payment_link.settlement_rejected')->latest('id')->first();

    expect($rejection)->not->toBeNull()
        ->and($rejection->causer_id)->toBe($admin->id);
});

it('rejects a second concurrent-style settlement attempt on the same link, applying exactly once', function (): void {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($ticket)->create();

    app(TicketPaymentService::class)->settle($link, 'REF-FIRST', $admin);

    expect(fn () => app(TicketPaymentService::class)->settle($link->refresh(), 'REF-SECOND', $admin))
        ->toThrow(InvalidStatusTransition::class);

    expect($link->refresh()->payment_method_reference)->toBe('REF-FIRST')
        ->and(TicketPaymentLink::query()->where('status', PaymentLinkStatus::Settled)->count())->toBe(1);
});

it('cancels the pending payment link in the same transaction as cancelling the ticket', function (): void {
    $manager = makePaymentSupportManager();
    $ticket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($ticket)->create();

    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Cancelled, $manager);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Cancelled)
        ->and($link->refresh()->status)->toBe(PaymentLinkStatus::Cancelled);
});

it('rejects settling a ticket that was cancelled between page-load and submit, keeping the cancellation intact', function (): void {
    $manager = makePaymentSupportManager();
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($ticket)->create();

    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Cancelled, $manager);

    expect(fn () => app(TicketPaymentService::class)->settle($link, 'REF-789', $admin))
        ->toThrow(InvalidStatusTransition::class)
        ->and($ticket->refresh()->status)->toBe(TicketStatus::Cancelled)
        ->and($link->refresh()->status)->toBe(PaymentLinkStatus::Cancelled);
});

it('produces zero rows in any accounting-adjacent table', function (): void {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($ticket)->create();

    app(TicketPaymentService::class)->settle($link, 'REF-999', $admin);

    expect(DB::table('journal_entries')->count())->toBe(0)
        ->and(DB::table('journal_entry_lines')->count())->toBe(0);

    foreach (['tax_definitions', 'accounts_receivable', 'accounts_payable', 'bills', 'expenses'] as $table) {
        if (Schema::hasTable($table)) {
            expect(DB::table($table)->count())->toBe(0);
        }
    }
});

it('restricts the settle-payment ability to System Admin, matching page-open, direct-action, and direct-service-call checks', function (): void {
    $admin = User::factory()->admin()->create();
    $manager = makePaymentSupportManager();
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    expect(app(TicketPolicy::class)->settlePayment($admin))->toBeTrue()
        ->and(app(TicketPolicy::class)->settlePayment($manager))->toBeFalse()
        ->and(app(TicketPolicy::class)->settlePayment($agent))->toBeFalse();

    $ticket = Ticket::factory()->chargeable()->create();
    TicketPaymentLink::factory()->for($ticket)->create();

    Livewire::actingAs($admin)->test(ListTickets::class)->assertTableActionVisible('settlePayment', $ticket);
    Livewire::actingAs($manager)->test(ListTickets::class)->assertTableActionHidden('settlePayment', $ticket);
    Livewire::actingAs($agent)->test(ListTickets::class)->assertTableActionHidden('settlePayment', $ticket);

    $link = $ticket->paymentLink;
    expect(fn () => app(TicketPaymentService::class)->settle($link, 'REF-000', $manager))
        ->toThrow(AuthorizationException::class);
});
