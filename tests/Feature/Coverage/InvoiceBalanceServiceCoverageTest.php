<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\OrderPaymentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Sales\InvoiceBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves every invoice balance label', function (): void {
    $service = app(InvoiceBalanceService::class);

    $draft = Invoice::factory()->create(['status' => InvoiceStatus::Draft]);
    expect($service->status($draft))->toBe('draft');

    $credited = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => 100,
        'credited_amount' => 100,
        'amount_paid' => 0,
    ]);
    expect($service->status($credited))->toBe('credited');

    $paid = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => 100,
        'credited_amount' => 0,
        'amount_paid' => 100,
    ]);
    expect($service->status($paid))->toBe('paid');
    $partiallyPaid = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => 100,
        'credited_amount' => 0,
        'amount_paid' => 25,
    ]);
    expect($service->status($partiallyPaid))->toBe('partially_paid');

    $sent = Invoice::factory()->create([
        'status' => InvoiceStatus::Sent,
        'issued_at' => now(),
        'total_amount' => 100,
        'credited_amount' => 0,
        'amount_paid' => 0,
        'sent_at' => now(),
    ]);
    expect($service->status($sent))->toBe('sent');

    $issued = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => 100,
        'credited_amount' => 0,
        'amount_paid' => 0,
        'sent_at' => null,
    ]);
    expect($service->status($issued))->toBe('issued');

    $partiallyCreditedAndPaid = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => 100,
        'credited_amount' => 40,
        'amount_paid' => 60,
    ]);
    expect($service->status($partiallyCreditedAndPaid))->toBe('credited');
});
it('synchronizes order payment status across no-claim unpaid partial and paid cases', function (): void {
    $service = app(InvoiceBalanceService::class);
    $service->syncOrder(null);

    $emptyOrder = Order::factory()->create();
    $service->syncOrder($emptyOrder);
    expect($emptyOrder->refresh()->payment_status)->toBe(OrderPaymentStatus::Unpaid);

    $partialOrder = Order::factory()->create();
    Invoice::factory()->for($partialOrder, 'order')->create([
        'issued_at' => now(),
        'total_amount' => 100,
        'credited_amount' => 0,
        'amount_paid' => 25,
    ]);
    $service->syncOrder($partialOrder);
    expect($partialOrder->refresh()->payment_status)->toBe(OrderPaymentStatus::PartiallyPaid);

    $paidOrder = Order::factory()->create();
    Invoice::factory()->for($paidOrder, 'order')->create([
        'issued_at' => now(),
        'total_amount' => 100,
        'credited_amount' => 20,
        'amount_paid' => 80,
    ]);
    $service->syncOrder($paidOrder);
    expect($paidOrder->refresh()->payment_status)->toBe(OrderPaymentStatus::Paid);
    $noClaimOrder = Order::factory()->create();
    Invoice::factory()->for($noClaimOrder, 'order')->create([
        'issued_at' => now(),
        'total_amount' => 100,
        'credited_amount' => 100,
        'amount_paid' => 0,
    ]);
    $service->syncOrder($noClaimOrder);
    expect($noClaimOrder->refresh()->payment_status)->toBe(OrderPaymentStatus::Paid);
});
