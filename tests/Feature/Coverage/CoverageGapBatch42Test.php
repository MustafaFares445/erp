<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PaymentTransaction;
use App\Models\Shipment;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\PurchaseOrderAcceptanceOrchestrator;
use App\Services\Sales\OrderCompletionEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers remaining order completion eligibility blockers', function (): void {
    $service = app(OrderCompletionEligibilityService::class);
    $customer = CustomerProfile::factory()->create();

    $notReleased = Order::factory()
        ->for($customer, 'customer')
        ->confirmed()
        ->create(['grand_total' => '0.00']);

    Invoice::factory()->create([
        'order_id' => $notReleased->getKey(),
        'customer_id' => $customer->getKey(),
        'subtotal' => '0.00',
        'tax_total' => '0.00',
        'total_amount' => '0.00',
        'amount_paid' => '0.00',
        'status' => 'issued',
        'issued_at' => now(),
    ]);

    $notReleasedProjection = $service->evaluate($notReleased);
    expect(collect($notReleasedProjection->blockers)->pluck('code'))
        ->toContain('order_not_released');

    $shipmentPending = Order::factory()
        ->for($customer, 'customer')
        ->create(['grand_total' => '0.00']);

    Invoice::factory()->create([
        'order_id' => $shipmentPending->getKey(),
        'customer_id' => $customer->getKey(),
        'subtotal' => '0.00',
        'tax_total' => '0.00',
        'total_amount' => '0.00',
        'amount_paid' => '0.00',
        'status' => 'issued',
        'issued_at' => now(),
    ]);
    Shipment::factory()->create([
        'order_id' => $shipmentPending->getKey(),
    ]);

    $shipmentProjection = $service->evaluate($shipmentPending);
    expect(collect($shipmentProjection->blockers)->pluck('code'))
        ->toContain('shipment_not_arrived');

    $draftInvoice = Order::factory()
        ->for($customer, 'customer')
        ->create(['grand_total' => '100.00']);
    OrderLine::factory()->for($draftInvoice)->create();
    Invoice::factory()->create([
        'order_id' => $draftInvoice->getKey(),
        'customer_id' => $customer->getKey(),
        'status' => 'draft',
        'issued_at' => null,
    ]);

    $draftProjection = $service->evaluate($draftInvoice);
    expect(collect($draftProjection->blockers)->pluck('code'))
        ->toContain('invoice_draft');
});

it('covers ticket payment settlement descriptions and unavailable ticket labels', function (): void {
    $ticket = new Ticket;
    $ticket->ticket_number = 'TCK-COV-42';

    $settledLink = new TicketPaymentLink;
    $settledLink->status = PaymentLinkStatus::Settled;
    $settledLink->setRelation('ticket', $ticket);

    $settled = new PaymentTransaction;
    $settled->status = PaymentTransactionStatus::Succeeded;
    $settled->setRelation('purpose', $settledLink);

    expect($settled->settlementDescription())
        ->toBe(__('admin.payments.settlement_state_description.settled_ticket'))
        ->and($settled->purposeLabel())
        ->toBe(__('admin.sales.payment_ui.ticket_purpose', ['number' => 'TCK-COV-42']));

    $attentionLink = new TicketPaymentLink;
    $attentionLink->status = PaymentLinkStatus::Pending;
    $attentionLink->setRelation('ticket', $ticket);

    $attention = new PaymentTransaction;
    $attention->status = PaymentTransactionStatus::Succeeded;
    $attention->setRelation('purpose', $attentionLink);

    expect($attention->settlementDescription())
        ->toBe(__('admin.payments.settlement_state_description.requires_attention_ticket'));

    $missingTicket = new TicketPaymentLink;
    $missingTicket->status = PaymentLinkStatus::Pending;
    $missingTicket->setRelation('ticket', null);

    $missing = new PaymentTransaction;
    $missing->status = PaymentTransactionStatus::Pending;
    $missing->setRelation('purpose', $missingTicket);

    expect($missing->purposeLabel())
        ->toBe(__('admin.sales.payment_ui.ticket_purpose_unavailable'));
});

it('covers purchase order acceptance precondition guards', function (): void {
    $actor = User::factory()->admin()->create();
    $orchestrator = app(PurchaseOrderAcceptanceOrchestrator::class);

    $draft = PurchaseOrder::factory()->create();

    expect(fn (): PurchaseOrder => $orchestrator->handle($actor, $draft))
        ->toThrow(DomainException::class, 'requires an accepted purchase order');

    $acceptedNotSent = PurchaseOrder::factory()->accepted($actor)->create([
        'sent_at' => null,
    ]);

    expect(fn (): PurchaseOrder => $orchestrator->handle($actor, $acceptedNotSent))
        ->toThrow(DomainException::class, 'Send the Purchase Order to the supplier');
});
