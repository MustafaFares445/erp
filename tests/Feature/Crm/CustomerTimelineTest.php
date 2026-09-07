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
