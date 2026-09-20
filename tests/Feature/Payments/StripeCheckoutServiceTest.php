<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\TicketPaymentLink;
use App\Services\Payments\Providers\FakeStripeClient;
use App\Services\Payments\Providers\StripeClientInterface;
use App\Services\Payments\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->fake = new FakeStripeClient;
    $this->app->instance(StripeClientInterface::class, $this->fake);
});

it('creates a checkout session for an issued invoice using its outstanding amount', function (): void {
    $customer = CustomerProfile::factory()->create();
    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => '150.00',
        'amount_paid' => '50.00',
    ]);

    $transaction = app(StripeCheckoutService::class)->createForInvoice($customer, $invoice, 'https://app.test/ok', 'https://app.test/cancel');

    expect($transaction->amount_minor)->toBe(10000)
        ->and($transaction->purpose_type)->toBe(Invoice::class)
        ->and($transaction->purpose_id)->toBe($invoice->getKey())
        ->and($transaction->checkout_session_id)->not->toBeNull()
        ->and(PaymentTransaction::query()->count())->toBe(1);
});

it("refuses to create a checkout session for someone else's invoice", function (): void {
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    $invoice = Invoice::factory()->for($otherCustomer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => '150.00',
    ]);

    expect(fn () => app(StripeCheckoutService::class)->createForInvoice($customer, $invoice, 'https://app.test/ok', 'https://app.test/cancel'))
        ->toThrow(DomainException::class);
});

it('refuses a fully paid invoice', function (): void {
    $customer = CustomerProfile::factory()->create();
    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => '100.00',
        'amount_paid' => '100.00',
    ]);

    expect(fn () => app(StripeCheckoutService::class)->createForInvoice($customer, $invoice, 'https://app.test/ok', 'https://app.test/cancel'))
        ->toThrow(DomainException::class);
});

it('validates a requested order prepayment amount against the order total', function (): void {
    $customer = CustomerProfile::factory()->create();
    $order = Order::factory()->for($customer, 'customer')->create(['grand_total' => '500.00']);

    $transaction = app(StripeCheckoutService::class)->createForOrder($customer, $order, 200.0, 'https://app.test/ok', 'https://app.test/cancel');

    expect($transaction->amount_minor)->toBe(20000)
        ->and($transaction->purpose_type)->toBe(Order::class);

    expect(fn () => app(StripeCheckoutService::class)->createForOrder($customer, $order, 600.0, 'https://app.test/ok', 'https://app.test/cancel'))
        ->toThrow(DomainException::class);
});

it('creates a checkout session for a pending chargeable ticket', function (): void {
    $customer = CustomerProfile::factory()->create();
    $link = TicketPaymentLink::factory()->create(['amount' => '75.00', 'currency' => 'USD']);
    $link->ticket()->update(['customer_id' => $customer->getKey()]);

    $transaction = app(StripeCheckoutService::class)->createForTicket($customer, $link->refresh(), 'https://app.test/ok', 'https://app.test/cancel');

    expect($transaction->amount_minor)->toBe(7500)
        ->and($transaction->purpose_type)->toBe(TicketPaymentLink::class)
        ->and($transaction->purpose_id)->toBe($link->getKey());
});
