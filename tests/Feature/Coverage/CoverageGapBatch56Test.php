<?php

declare(strict_types=1);

use App\Enums\CreditNoteStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\BusinessNotification;
use App\Services\Accounting\RefundService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Payments\CustomerDepositApplicationService;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use App\Services\Sales\SalesProcurementRequirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('sends database notifications synchronously for application users', function (): void {
    Notification::fake();

    NotificationTemplate::query()->create([
        'key' => NotificationEventKey::InvoiceIssued->value,
        'locale' => 'en',
        'channel' => NotificationChannel::Database,
        'subject' => null,
        'body' => 'Invoice {{ name }}',
        'variables' => ['name'],
        'is_active' => true,
    ]);

    $user = User::factory()->create();

    $delivery = app(NotificationDispatcher::class)->dispatch(
        $user,
        NotificationEventKey::InvoiceIssued,
        ['name' => 'INV-SYNC'],
        channel: NotificationChannel::Database,
        sendNow: true,
    );

    expect($delivery->status->value)->toBe('queued');

    Notification::assertSentTo($user, BusinessNotification::class);
});

it('caps refund approval to the selected confirmed credit note', function (): void {
    $customer = CustomerProfile::factory()->create();
    $credit = CreditNote::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_id' => null,
        'grand_total' => '25.00',
        'status' => CreditNoteStatus::Confirmed->value,
        'reversed_at' => null,
    ]);
    $refund = Refund::factory()->create([
        'customer_id' => $customer->getKey(),
        'credit_note_id' => $credit->getKey(),
        'invoice_id' => null,
        'amount' => '10.00',
        'status' => RefundStatus::Draft->value,
    ]);

    $approved = app(RefundService::class)->approve(User::factory()->admin()->create(), $refund);

    expect($approved->status)->toBe(RefundStatus::Approved);
});

it('rejects paying a refund through an inactive payment method', function (): void {
    $customer = CustomerProfile::factory()->create();
    $method = PaymentMethod::factory()->create(['is_active' => false]);
    $refund = Refund::factory()->create([
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => '10.00',
        'status' => RefundStatus::Approved->value,
        'approved_at' => now(),
    ]);

    expect(fn (): Refund => app(RefundService::class)->pay(
        User::factory()->admin()->create(),
        $refund,
    ))->toThrow(DomainException::class, 'active payment method with a posting account');
});

it('returns early when a deposit payment is already allocated to the invoice', function (): void {
    $customer = CustomerProfile::factory()->create();
    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-COV-056',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '50.00',
        'currency' => 'AED',
        'payment_date' => today(),
        'status' => PaymentStatus::Posted->value,
        'posted_at' => now(),
    ]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => 'issued',
        'issued_at' => now(),
        'total_amount' => '50.00',
        'amount_paid' => '0.00',
    ]);

    $payment->allocations()->create([
        'invoice_id' => $invoice->getKey(),
        'amount' => '10.00',
    ]);

    $method = new ReflectionMethod(CustomerDepositApplicationService::class, 'applyOneDeposit');

    expect($method->invoke(
        app(CustomerDepositApplicationService::class),
        User::factory()->admin()->create(),
        $payment,
        $invoice,
        40.0,
    ))->toBeNull();
});

it('adds awaiting-fulfillment orders to sales dashboard attention items', function (): void {
    Order::factory()->create([
        'status' => OrderStatus::Confirmed->value,
        'confirmed_at' => now(),
        'grand_total' => '250.00',
    ]);

    $filters = SalesDashboardFilters::fromPageFilters([
        'period' => SalesDashboardFilters::PERIOD_THIS_MONTH,
    ]);

    $items = collect(app(SalesDashboardMetricsService::class)->attentionItems($filters))->keyBy('key');

    expect($items)->toHaveKey('awaiting_fulfillment')
        ->and($items['awaiting_fulfillment']['count'])->toBe(1);
});

it('marks fully received procurement demand fulfilled without requeueing it', function (): void {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    $orderLine = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 5,
        'unit_id' => $variant->unit_id,
    ]);

    $purchaseOrder = PurchaseOrder::factory()->create();
    $purchaseLine = PurchaseOrderLine::factory()
        ->for($purchaseOrder)
        ->for($variant, 'productVariant')
        ->create([
            'unit_id' => $variant->unit_id,
            'quantity_ordered' => 5,
        ]);
    $purchaseLine->forceFill([
        'base_quantity' => '5.000000',
        'received_base_quantity' => '5.000000',
    ])->save();

    $requirement = $order->procurementRequirements()->create([
        'order_line_id' => $orderLine->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_order_id' => $purchaseOrder->getKey(),
        'purchase_order_line_id' => $purchaseLine->getKey(),
        'required_base_quantity' => '5.000000',
        'fulfilled_base_quantity' => '0.000000',
        'status' => 'purchasing',
    ]);

    $requeued = app(SalesProcurementRequirementService::class)->requeueFromPurchaseOrder(
        $purchaseOrder,
        'Supplier completed the line',
    );

    expect($requeued)->toBeEmpty()
        ->and($requirement->refresh()->status)->toBe('fulfilled')
        ->and((float) $requirement->fulfilled_base_quantity)->toBe(5.0);
});
