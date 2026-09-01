<?php

declare(strict_types=1);

use App\Enums\PaymentLinkStatus;
use App\Enums\TicketPriority;
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

it('saves a chargeable ticket with an amount, currency, and pending payment link', function (): void {
    $customer = CustomerProfile::factory()->create();
    $admin = User::factory()->admin()->create();

    $ticket = app(TicketIntakeService::class)->create([
        'customer_id' => $customer->id,
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'Chargeable repair',
        'description' => 'Description',
        'is_chargeable' => true,
        'amount' => 150.50,
        'currency' => 'usd',
    ], $admin);

    $link = $ticket->paymentLink;

    expect($link)->not->toBeNull()
        ->and((float) $link->amount)->toBe(150.50)
        ->and($link->currency)->toBe('usd')
        ->and($link->status)->toBe(PaymentLinkStatus::Pending);
});

it('rejects a chargeable ticket missing an amount or currency, even via a direct service call bypassing the form', function (): void {
    $customer = CustomerProfile::factory()->create();
    $admin = User::factory()->admin()->create();

    expect(fn () => app(TicketIntakeService::class)->create([
        'customer_id' => $customer->id,
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'Missing amount',
        'description' => 'Description',
        'is_chargeable' => true,
    ], $admin))->toThrow(ValidationException::class);

    expect(Ticket::query()->where('title', 'Missing amount')->exists())->toBeFalse();
});

it('creates a chargeable ticket through the actual Create Ticket form, with the amount/currency fields revealed by the chargeable toggle', function (): void {
    $customer = CustomerProfile::factory()->create();
    $manager = makePaymentSupportManager();

    Livewire::actingAs($manager)
        ->test(CreateTicket::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'type' => TicketType::GeneralSupport->value,
            'priority' => TicketPriority::Normal->value,
            'title' => 'Chargeable via the form',
            'description' => 'Description',
            'is_chargeable' => false,
        ])
        ->assertFormFieldIsHidden('amount')
        ->assertFormFieldIsHidden('currency')
        ->fillForm(['is_chargeable' => true])
        ->assertFormFieldIsVisible('amount')
        ->assertFormFieldIsVisible('currency')
        ->call('create')
        ->assertHasFormErrors(['amount'])
        ->fillForm(['amount' => 75, 'currency' => 'AED'])
        ->call('create')
        ->assertHasNoFormErrors();

    $ticket = Ticket::query()->where('title', 'Chargeable via the form')->firstOrFail();

    expect($ticket->status)->toBe(TicketStatus::PendingPayment)
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

    // pending_payment only ever leaves via settlement (system, TicketPaymentService::settle())
    // or cancellation — a direct transition() call to live/assigned/in_progress is rejected.
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

// FR-044/SC-003: a second settlement attempt after the first has already landed on Settled is
// exactly what the loser of a real concurrent race observes once it acquires the row lock
// (mirrors tests/Feature/Inventory/OperationGuardsTest.php's "already processed" convention) —
// the lockForUpdate() + re-check inside settle()'s own transaction is what makes this safe under
// true concurrency, not just under this sequential re-invocation.
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

    // The general ledger exists as of spec 018 but nothing posts to it
    // automatically (FR-034, SC-008), so settling a chargeable ticket leaves it
    // empty. The remaining five tables are still unbuilt.
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
