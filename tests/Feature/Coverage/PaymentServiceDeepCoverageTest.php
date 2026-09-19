<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );
});

function paymentServiceCoveragePayment(
    CustomerProfile $customer,
    PaymentMethod $method,
    string $number,
    string $amount = '10.00',
): Payment {
    return Payment::query()->forceCreate([
        'payment_number' => $number,
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => $amount,
        'currency' => 'AED',
        'payment_date' => today(),
        'status' => PaymentStatus::Draft,
    ]);
}

it('covers inactive method proof and over-allocation payment guards', function (): void {
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $service = app(PaymentService::class);

    $inactive = PaymentMethod::factory()->create(['is_active' => false]);
    expect(fn () => $service->createDraft($actor, [
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $inactive->getKey(),
        'amount' => 10,
        'currency' => 'AED',
    ]))->toThrow(DomainException::class, 'selected payment method is not active');

    $inactivePayment = paymentServiceCoveragePayment($customer, $inactive, 'PAY-SVC-INACTIVE');
    expect(fn () => $service->post($actor, $inactivePayment, []))
        ->toThrow(DomainException::class, 'posted payment requires an active payment method');

    $proofMethod = PaymentMethod::factory()->create([
        'is_active' => true,
        'requires_proof' => true,
    ]);
    $proofPayment = paymentServiceCoveragePayment($customer, $proofMethod, 'PAY-SVC-PROOF');
    expect(fn () => $service->post($actor, $proofPayment, []))
        ->toThrow(DomainException::class, 'requires payment proof');

    $active = PaymentMethod::factory()->create(['is_active' => true, 'requires_proof' => false]);
    $overAllocated = paymentServiceCoveragePayment($customer, $active, 'PAY-SVC-OVER', '10.00');
    expect(fn () => $service->post($actor, $overAllocated, [[
        'invoice_id' => 999999,
        'amount' => 11,
    ]]))->toThrow(DomainException::class, 'cannot exceed the payment amount');
});

it('reverses posted payment journal entries through the service', function (): void {
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $method = PaymentMethod::factory()->create();
    $payment = paymentServiceCoveragePayment($customer, $method, 'PAY-SVC-JOURNAL', '100.00');
    $payment->forceFill(['status' => PaymentStatus::Posted, 'posted_at' => now()])->save();

    $entry = JournalEntry::factory()->postedAndBalanced('100.00')->create([
        'source_type' => Payment::class,
        'source_id' => $payment->getKey(),
    ]);

    $reversed = app(PaymentService::class)->reverse($actor, $payment->refresh());
    expect($reversed->status)->toBe(PaymentStatus::Reversed)
        ->and($entry->refresh()->reversal()->exists())->toBeTrue();
});

it('restores allocations when reversing a posted payment', function (): void {
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $method = PaymentMethod::factory()->create();
    $payment = paymentServiceCoveragePayment($customer, $method, 'PAY-SVC-ALLOC', '20.00');
    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'total_amount' => '100.00',
        'amount_paid' => '20.00',
        'issued_at' => now(),
    ]);

    $payment->allocations()->create([
        'invoice_id' => $invoice->getKey(),
        'amount' => '20.00',
    ]);
    $payment->forceFill(['status' => PaymentStatus::Posted, 'posted_at' => now()])->save();

    $reversed = app(PaymentService::class)->reverse($actor, $payment->refresh());
    expect($reversed->status)->toBe(PaymentStatus::Reversed)
        ->and((float) $invoice->refresh()->amount_paid)->toBe(0.0);
});
