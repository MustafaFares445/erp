<?php

declare(strict_types=1);

use App\Enums\InteractionDirection;
use App\Enums\InteractionType;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\Interaction;
use App\Models\Invoice;
use App\Models\MaintenanceRecord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Crm\Timeline\Sources\ActivitySource;
use App\Services\Crm\Timeline\Sources\InteractionSource;
use App\Services\Crm\Timeline\Sources\InvoiceSource;
use App\Services\Crm\Timeline\Sources\MaintenanceRecordSource;
use App\Services\Crm\Timeline\Sources\OrderSource;
use App\Services\Crm\Timeline\Sources\PaymentSource;
use App\Services\Crm\Timeline\Sources\QuotationSource;
use App\Services\Crm\Timeline\Sources\TicketSource;
use App\Services\Crm\Timeline\Sources\VisitSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

uses(RefreshDatabase::class);

it('covers timeline source detail branches', function (): void {
    $invoiceDetail = new ReflectionMethod(InvoiceSource::class, 'detail');
    $overdue = Invoice::factory()->create([
        'status' => 'issued',
        'issued_at' => now()->subDays(10),
        'due_date' => today()->subDays(3),
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
    ]);
    $future = Invoice::factory()->create([
        'status' => 'issued',
        'issued_at' => now(),
        'due_date' => today()->addDays(3),
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
    ]);
    $noDue = Invoice::factory()->create(['due_date' => null]);

    expect($invoiceDetail->invoke(new InvoiceSource, $overdue))->toContain('days overdue')
        ->and($invoiceDetail->invoke(new InvoiceSource, $future))->toBe('Due '.$future->due_date->toDateString())
        ->and($invoiceDetail->invoke(new InvoiceSource, $noDue))->toBeNull();

    $orderDetail = new ReflectionMethod(OrderSource::class, 'detail');
    $scheduled = Order::factory()->create([
        'delivery_type' => 'home_delivery',
        'scheduled_at' => now()->addDay(),
    ]);
    expect($orderDetail->invoke(new OrderSource, $scheduled))
        ->toContain('Home Delivery')
        ->toContain('Scheduled');

    $paymentDetail = new ReflectionMethod(PaymentSource::class, 'detail');
    $paymentCustomer = CustomerProfile::factory()->create();
    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-TL-SOURCE-DETAIL',
        'customer_id' => $paymentCustomer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '50.00',
        'payment_date' => today(),
        'external_reference' => 'EXT-COVERAGE-1',
    ]);
    expect($paymentDetail->invoke(new PaymentSource, $payment->load('paymentMethod')))
        ->toContain('Ref EXT-COVERAGE-1');

    $quotationDetail = new ReflectionMethod(QuotationSource::class, 'detail');
    $quotation = Quotation::factory()->create([
        'expires_at' => today()->addDays(5),
        'decision_note' => 'Coverage decision note',
    ]);
    expect($quotationDetail->invoke(new QuotationSource, $quotation))
        ->toContain('Valid until')
        ->toContain('Coverage decision note');

    $ticketDetail = new ReflectionMethod(TicketSource::class, 'detail');
    $ticket = Ticket::factory()->create([
        'response_breached' => true,
        'resolution_breached' => true,
    ]);
    expect($ticketDetail->invoke(new TicketSource, $ticket))
        ->toContain('Response SLA breached')
        ->toContain('Resolution SLA breached');

    $visitDetail = new ReflectionMethod(VisitSource::class, 'detail');
    $visit = CustomerVisit::factory()->create([
        'checked_in_at' => now()->subHour(),
        'checked_out_at' => now(),
        'outcome' => 'Coverage visit completed',
    ]);
    expect($visitDetail->invoke(new VisitSource, $visit))
        ->toContain('Checked in')
        ->toContain('Checked out')
        ->toContain('Coverage visit completed');
});

it('covers timeline source related document links', function (): void {
    $customer = CustomerProfile::factory()->create();

    $quotation = Quotation::factory()->create(['customer_id' => $customer->getKey()]);
    $order = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'quotation_id' => $quotation->getKey(),
    ]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'order_id' => $order->getKey(),
    ]);

    $quotation->forceFill(['converted_order_id' => $order->getKey()])->save();

    $invoiceEvent = (new InvoiceSource)->hydrate([$invoice->getKey()])[$invoice->getKey()];
    $orderEvent = (new OrderSource)->hydrate([$order->getKey()])[$order->getKey()];
    $quotationEvent = (new QuotationSource)->hydrate([$quotation->getKey()])[$quotation->getKey()];

    expect($invoiceEvent->relatedLinks)->toHaveCount(1)
        ->and($orderEvent->relatedLinks)->toHaveCount(1)
        ->and($quotationEvent->relatedLinks)->toHaveCount(1);

    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-TL-SOURCE-LINK',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '25.00',
        'payment_date' => today(),
    ]);
    $payment->allocations()->create([
        'invoice_id' => $invoice->getKey(),
        'amount' => '25.00',
    ]);

    $paymentEvent = (new PaymentSource)->hydrate([$payment->getKey()])[$payment->getKey()];
    expect($paymentEvent->relatedLinks)->toHaveCount(1);

    $ticket = Ticket::factory()->create(['customer_id' => $customer->getKey()]);
    $variant = ProductVariant::factory()->create();
    $maintenance = MaintenanceRecord::factory()->create([
        'customer_id' => $customer->getKey(),
        'ticket_id' => $ticket->getKey(),
        'invoice_id' => $invoice->getKey(),
        'product_variant_id' => $variant->getKey(),
    ]);

    $maintenanceEvent = (new MaintenanceRecordSource)->hydrate([$maintenance->getKey()])[$maintenance->getKey()];
    expect($maintenanceEvent->relatedLinks)->toHaveCount(2);

    $visit = CustomerVisit::factory()->create(['customer_id' => $customer->getKey()]);
    $interactionActor = User::factory()->create();
    $interaction = Interaction::query()->create([
        'subject_type' => CustomerProfile::class,
        'subject_id' => $customer->getKey(),
        'type' => InteractionType::Call,
        'direction' => InteractionDirection::Outbound,
        'occurred_at' => now(),
        'summary' => 'Visit-linked interaction coverage',
        'employee_id' => $interactionActor->getKey(),
        'customer_visit_id' => $visit->getKey(),
    ]);

    $interactionEvent = (new InteractionSource)->hydrate([$interaction->getKey()])[$interaction->getKey()];

    expect($interactionEvent->relatedLinks)->toHaveCount(1)
        ->and($interactionEvent->link)->not->toBeNull();
});

it('covers activity source no-permission and non-scalar status branches', function (): void {
    $source = new ActivitySource;
    $customer = CustomerProfile::factory()->create();
    $actor = User::factory()->employee()->create();

    expect($source->permission($actor))->toBeFalse()
        ->and($source->subQuery($customer, $actor, null, null, null)->count())->toBe(0);

    $log = new AuditLog;
    $log->forceFill([
        'attribute_changes' => new Collection([
            'old' => ['status' => ['not-scalar']],
            'attributes' => ['status' => ['still-not-scalar']],
        ]),
    ]);

    $detail = new ReflectionMethod(ActivitySource::class, 'detail');

    expect($detail->invoke($source, $log))->toBeNull();
});

it('covers shared timeline source search from and until query branches', function (): void {
    $customer = CustomerProfile::factory()->create();
    $actor = User::factory()->employee()->create();
    $from = now()->subDays(30);
    $until = now()->addDay();

    $sources = [
        new InvoiceSource,
        new OrderSource,
        new PaymentSource,
        new QuotationSource,
        new TicketSource,
        new VisitSource,
        new MaintenanceRecordSource,
        new InteractionSource,
    ];

    foreach ($sources as $source) {
        expect($source->subQuery($customer, $actor, $from, $until, 'coverage-search')->count())
            ->toBeInt();
    }

    $activity = new ActivitySource;
    $applyFilters = new ReflectionMethod(ActivitySource::class, 'applyFilters');
    $query = DB::table('activity_log');
    $filtered = $applyFilters->invoke($activity, $query, $from, $until, 'coverage-search');
    expect($filtered->count())->toBeInt();

    $detail = new ReflectionMethod(ActivitySource::class, 'detail');
    expect($detail->invoke($activity, new AuditLog))->toBeNull();
});

it('skips payment allocation timeline links when the allocated invoice is soft deleted', function (): void {
    $customer = CustomerProfile::factory()->create();
    $invoice = Invoice::factory()->create(['customer_id' => $customer->getKey()]);
    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-TL-DELETED-INVOICE',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '10.00',
        'payment_date' => today(),
    ]);

    $payment->allocations()->create([
        'invoice_id' => $invoice->getKey(),
        'amount' => '10.00',
    ]);
    $invoice->delete();

    $event = (new PaymentSource)->hydrate([$payment->getKey()])[$payment->getKey()];

    expect($event->relatedLinks)->toBe([]);
});
it('uses the translated CRM activity title when a translation exists', function (): void {
    Lang::addLines([
        'admin.crm.timeline.activity.coverage.translated' => 'Translated coverage activity',
    ], app()->getLocale());

    $log = new AuditLog;
    $log->forceFill(['description' => 'coverage.translated']);

    $title = new ReflectionMethod(ActivitySource::class, 'title');

    expect($title->invoke(new ActivitySource, $log))->toBe('Translated coverage activity');
});
