<?php

declare(strict_types=1);

use App\Data\Crm\InteractionData;
use App\Enums\CrmPermission;
use App\Enums\EmployeePermission;
use App\Enums\InteractionDirection;
use App\Enums\InteractionType;
use App\Enums\SalesPermission;
use App\Enums\SupportPermission;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\Invoice;
use App\Models\MaintenanceRecord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Crm\CustomerTimelineService;
use App\Services\Crm\InteractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * WP-3.1 (GAP-UI-03, CR-05) — the customer 360 timeline: every source
 * reachable in one reverse-chronological, permission-filtered, paginated
 * stream instead of an account manager assembling it from eight screens.
 */
function timelineFullAccessActor(): User
{
    $actor = User::factory()->admin()->create();

    foreach ([
        SalesPermission::QuotationView,
        SalesPermission::OrderView,
        SalesPermission::InvoiceView,
        SalesPermission::PaymentView,
    ] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission->value, 'web'));
    }

    foreach ([SupportPermission::TicketView, SupportPermission::MaintenanceRequestView] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission->value, 'web'));
    }

    $actor->givePermissionTo(Permission::findOrCreate(CrmPermission::InteractionView->value, 'web'));
    $actor->givePermissionTo(Permission::findOrCreate(EmployeePermission::VisitView->value, 'web'));

    return $actor;
}

/** @return array{0: CustomerProfile, 1: array<string, int>} */
function timelineSeededCustomer(): array
{
    $customer = CustomerProfile::factory()->create();

    $quotation = Quotation::factory()->create(['customer_id' => $customer->getKey(), 'issue_date' => now()->subDays(6)]);
    $order = Order::factory()->create(['customer_id' => $customer->getKey(), 'created_at' => now()->subDays(5)]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->getKey(), 'invoice_date' => now()->subDays(4)]);
    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-TL-001',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '50.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => now()->subDays(3)->toDateString(),
        'status' => 'posted',
        'posted_at' => now()->subDays(3),
    ]);
    $ticket = Ticket::factory()->create(['customer_id' => $customer->getKey(), 'created_at' => now()->subDays(2)]);
    $visit = CustomerVisit::factory()->create(['customer_id' => $customer->getKey(), 'planned_at' => now()->subDay()]);
    $maintenance = MaintenanceRecord::factory()->create(['customer_id' => $customer->getKey(), 'created_at' => now()]);

    $actor = User::factory()->admin()->create();
    $actor->givePermissionTo(Permission::findOrCreate(CrmPermission::InteractionCreate->value, 'web'));

    $interaction = app(InteractionService::class)->log(new InteractionData(
        subject: $customer,
        type: InteractionType::Call,
        direction: InteractionDirection::Outbound,
        occurredAt: now()->subDays(7),
        summary: 'Renewal check-in call',
    ), $actor);

    return [$customer, [
        'quotation' => $quotation->getKey(),
        'order' => $order->getKey(),
        'invoice' => $invoice->getKey(),
        'payment' => $payment->getKey(),
        'ticket' => $ticket->getKey(),
        'visit' => $visit->getKey(),
        'maintenance_record' => $maintenance->getKey(),
        'interaction' => $interaction->getKey(),
    ]];
}

it('unions all eight source types into one reverse-chronological stream', function (): void {
    [$customer] = timelineSeededCustomer();
    $actor = timelineFullAccessActor();

    $page = app(CustomerTimelineService::class)->timeline($customer, $actor, null, null);

    expect(collect($page->items())->pluck('type')->unique()->sort()->values()->all())
        ->toBe(collect(CustomerTimelineService::TYPES)->sort()->values()->all());

    $occurredAtValues = collect($page->items())->pluck('occurred_at')->map(fn ($v): string => (string) $v)->all();
    $sorted = $occurredAtValues;
    rsort($sorted);
    expect($occurredAtValues)->toBe($sorted);
});

it('filters the timeline by type and date range', function (): void {
    [$customer] = timelineSeededCustomer();
    $actor = timelineFullAccessActor();

    $service = app(CustomerTimelineService::class);

    $typeFiltered = $service->timeline($customer, $actor, null, null, ['ticket']);
    expect(collect($typeFiltered->items())->pluck('type')->unique()->all())->toBe(['ticket']);

    $dateFiltered = $service->timeline($customer, $actor, now()->subDays(3), null);
    expect(collect($dateFiltered->items())->pluck('type')->all())->not->toContain('interaction');
});

it('does not load the whole customer history to paginate the timeline', function (): void {
    [$customer] = timelineSeededCustomer();
    Ticket::factory()->count(50)->create(['customer_id' => $customer->getKey()]);
    $actor = timelineFullAccessActor();

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(CustomerTimelineService::class)->timeline($customer, $actor, null, null, perPage: 10);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(12);
});

it("filters the timeline to a support-only actor's own module permissions", function (): void {
    [$customer] = timelineSeededCustomer();

    $supportOnly = User::factory()->admin()->create();
    $supportOnly->givePermissionTo(Permission::findOrCreate(SupportPermission::TicketView->value, 'web'));
    $supportOnly->givePermissionTo(Permission::findOrCreate(EmployeePermission::VisitView->value, 'web'));

    $page = app(CustomerTimelineService::class)->timeline($customer, $supportOnly, null, null);
    $types = collect($page->items())->pluck('type')->unique()->sort()->values()->all();

    expect($types)->toBe(['ticket', 'visit'])
        ->and($types)->not->toContain('invoice');
});

it('reports the same outstanding figure as the accounts receivable service', function (): void {
    [$customer] = timelineSeededCustomer();

    $summary = app(CustomerTimelineService::class)->summary($customer);
    $expected = app(AccountsReceivableService::class)->customerDetail($customer);

    expect($summary['outstanding']['outstanding_minor'])->toBe($expected['outstanding_minor']);
});

it('shows activity rows with their causer and a status transition', function (): void {
    [$customer, $ids] = timelineSeededCustomer();
    $actor = timelineFullAccessActor();
    $causer = User::factory()->admin()->create(['name' => 'Layla Nasser']);
    $ticket = Ticket::query()->findOrFail($ids['ticket']);

    activity()->performedOn($ticket)->causedBy($causer)
        ->withChanges(['old' => ['status' => 'assigned'], 'attributes' => ['status' => 'in_progress']])
        ->withProperties(['source_channel' => 'dashboard'])
        ->log('support.ticket.status_changed');

    $page = app(CustomerTimelineService::class)->timeline($customer, $actor, null, null);
    $activityEvent = collect($page->items())->first(fn ($event): bool => $event->type === 'activity' && $event->detail !== null);

    expect($activityEvent)->not->toBeNull()
        ->and($activityEvent->actorName)->toBe('Layla Nasser')
        ->and($activityEvent->detail)->toBe('Assigned → In Progress')
        ->and($activityEvent->isSystem)->toBeTrue();
});

it("scopes activity rows to the acting user's own subject permissions", function (): void {
    [$customer, $ids] = timelineSeededCustomer();
    $ticket = Ticket::query()->findOrFail($ids['ticket']);
    $invoice = Invoice::query()->findOrFail($ids['invoice']);
    $causer = User::factory()->admin()->create();

    activity()->performedOn($ticket)->causedBy($causer)->log('support.ticket.assigned');
    activity()->performedOn($invoice)->causedBy($causer)->log('sales.invoice.issued');

    $supportOnly = User::factory()->admin()->create();
    $supportOnly->givePermissionTo(Permission::findOrCreate(SupportPermission::TicketView->value, 'web'));

    $page = app(CustomerTimelineService::class)->timeline($customer, $supportOnly, null, null);
    $activityEvents = collect($page->items())->filter(fn ($event): bool => $event->type === 'activity');

    expect($activityEvents)->toHaveCount(1);
});

it('excludes activity rows when the activity toggle is switched off', function (): void {
    [$customer] = timelineSeededCustomer();
    $actor = timelineFullAccessActor();

    $withActivity = app(CustomerTimelineService::class)->timeline($customer, $actor, null, null, showActivity: true);
    $withoutActivity = app(CustomerTimelineService::class)->timeline($customer, $actor, null, null, showActivity: false);

    expect(collect($withActivity->items())->pluck('type'))->toContain('activity')
        ->and(collect($withoutActivity->items())->pluck('type'))->not->toContain('activity');
});

it('exposes a translated status label instead of the raw enum value for every status-bearing event', function (): void {
    [$customer, $ids] = timelineSeededCustomer();
    $actor = timelineFullAccessActor();

    $ticket = Ticket::query()->findOrFail($ids['ticket']);
    $ticket->forceFill(['status' => 'in_progress'])->saveQuietly();

    $page = app(CustomerTimelineService::class)->timeline($customer, $actor, null, null);
    $expectedLabelByType = [
        'quotation' => Quotation::query()->findOrFail($ids['quotation'])->status->label(),
        'order' => Order::query()->findOrFail($ids['order'])->status->label(),
        'invoice' => Invoice::query()->findOrFail($ids['invoice'])->status->label(),
        'payment' => Payment::query()->findOrFail($ids['payment'])->status->label(),
        'ticket' => $ticket->status->label(),
        'visit' => CustomerVisit::query()->findOrFail($ids['visit'])->status->label(),
        'maintenance_record' => MaintenanceRecord::query()->findOrFail($ids['maintenance_record'])->status->label(),
    ];

    expect($ticket->status->label())->not->toBe('in_progress');

    foreach ($page->items() as $event) {
        if (! array_key_exists($event->type, $expectedLabelByType)) {
            continue;
        }

        expect($event->statusLabel)->toBe($expectedLabelByType[$event->type]);

        if ($event->type === 'ticket') {
            expect($event->statusLabel)->not->toBe('in_progress');
        }
    }
});

it('exposes amountMinor for every money-bearing event type', function (): void {
    [$customer] = timelineSeededCustomer();
    $actor = timelineFullAccessActor();

    $page = app(CustomerTimelineService::class)->timeline($customer, $actor, null, null);
    $moneyTypes = ['quotation', 'order', 'invoice', 'payment'];

    foreach ($page->items() as $event) {
        if (in_array($event->type, $moneyTypes, true)) {
            expect($event->amountMinor)->not->toBeNull();
        }
    }
});

it('links an interaction event to its ticket instead of leaving it a dead end', function (): void {
    $customer = CustomerProfile::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->getKey()]);
    $actor = timelineFullAccessActor();
    $loggingActor = User::factory()->admin()->create();
    $loggingActor->givePermissionTo(Permission::findOrCreate(CrmPermission::InteractionCreate->value, 'web'));

    $interaction = app(InteractionService::class)->log(new InteractionData(
        subject: $customer,
        type: InteractionType::Call,
        direction: InteractionDirection::Outbound,
        occurredAt: now(),
        summary: 'Ticket follow-up call',
        ticketId: $ticket->getKey(),
    ), $loggingActor);

    $page = app(CustomerTimelineService::class)->timeline($customer, $actor, null, null);
    $interactionEvent = collect($page->items())->first(fn ($event): bool => $event->type === 'interaction' && $event->id === $interaction->getKey());

    expect($interactionEvent)->not->toBeNull()
        ->and($interactionEvent->link)->not->toBeNull()
        ->and($interactionEvent->relatedLinks)->not->toBeEmpty();
});

it('filters the timeline by a searched reference number', function (): void {
    [$customer, $ids] = timelineSeededCustomer();
    $actor = timelineFullAccessActor();
    $quotation = Quotation::query()->findOrFail($ids['quotation']);

    $page = app(CustomerTimelineService::class)->timeline($customer, $actor, null, null, search: $quotation->quotation_number);

    expect(collect($page->items())->pluck('type')->unique()->all())->toBe(['quotation']);
});

it('excludes draft and cancelled invoices from lifetime invoiced', function (): void {
    $customer = CustomerProfile::factory()->create();

    Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'total_amount' => '214.20',
        'status' => 'issued',
    ]);
    Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'total_amount' => '378.00',
        'status' => 'draft',
    ]);
    Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'total_amount' => '99.00',
        'status' => 'cancelled',
    ]);

    $summary = app(CustomerTimelineService::class)->summary($customer);

    expect($summary['lifetime_invoiced_minor'])->toBe(21420);
});

it('returns an empty timeline when the actor has no permitted timeline sources', function (): void {
    $customer = CustomerProfile::factory()->create();
    $actor = User::factory()->employee()->create();

    $page = app(CustomerTimelineService::class)->timeline(
        $customer,
        $actor,
        null,
        null,
        showActivity: false,
    );

    expect($page->total())->toBe(0)
        ->and($page->items())->toBe([]);
});

it('covers all timeline fallback event occurred-at branches', function (): void {
    $service = app(CustomerTimelineService::class);
    $method = new ReflectionMethod(CustomerTimelineService::class, 'fallbackEvent');

    $carbon = now();
    $fromCarbon = $method->invoke($service, [
        'id' => 11,
        'type' => 'missing_event',
        'occurred_at' => $carbon,
    ]);
    $fromString = $method->invoke($service, [
        'id' => 12,
        'type' => 'missing_event',
        'occurred_at' => '2026-09-19 10:30:00',
    ]);
    $fromUnknown = $method->invoke($service, [
        'id' => 13,
        'type' => 'missing_event',
        'occurred_at' => 123,
    ]);

    expect($fromCarbon->occurredAt->equalTo($carbon))->toBeTrue()
        ->and($fromString->occurredAt->toDateTimeString())->toBe('2026-09-19 10:30:00')
        ->and($fromUnknown->occurredAt)->not->toBeNull()
        ->and($fromCarbon->title)->toBe('Missing Event')
        ->and($fromCarbon->statusLabel)->toBeNull()
        ->and($fromCarbon->amountMinor)->toBeNull();
});
