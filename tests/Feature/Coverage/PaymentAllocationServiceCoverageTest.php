<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function paymentAllocationCoveragePayment(CustomerProfile $customer, float $amount = 100): Payment
{
    Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );
    $method = PaymentMethod::factory()->create();

    return Payment::query()->forceCreate([
        'payment_number' => 'PAY-ALLOC-'.fake()->unique()->numerify('######'),
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => number_format($amount, 2, '.', ''),
        'currency' => 'AED',
        'payment_date' => today()->toDateString(),
        'status' => 'draft',
    ]);
}

it('allocates and restores payment amounts against an issued invoice', function (): void {
    $customer = CustomerProfile::factory()->create();
    $payment = paymentAllocationCoveragePayment($customer, 100);
    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
    ]);

    $service = app(PaymentAllocationService::class);
    $allocation = $service->allocate($payment, $invoice->getKey(), 40.0);

    expect((float) $allocation->amount)->toBe(40.0)
        ->and((float) $invoice->refresh()->amount_paid)->toBe(40.0);

    $restored = $service->restore($allocation);
    expect((float) $restored->amount_paid)->toBe(0.0);
});

it('rejects invalid payment allocation scenarios', function (): void {
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    $payment = paymentAllocationCoveragePayment($customer, 100);
    $service = app(PaymentAllocationService::class);

    expect(fn () => $service->allocate($payment, 1, 0.0))
        ->toThrow(DomainException::class, 'greater than zero');

    $draftInvoice = Invoice::factory()->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Draft,
        'issued_at' => null,
        'total_amount' => '100.00',
    ]);
    expect(fn () => $service->allocate($payment, $draftInvoice->getKey(), 10.0))
        ->toThrow(DomainException::class, 'issued invoices');

    $foreignInvoice = Invoice::factory()->for($otherCustomer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => '100.00',
    ]);
    expect(fn () => $service->allocate($payment, $foreignInvoice->getKey(), 10.0))
        ->toThrow(DomainException::class, 'another customer');

    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => '50.00',
        'amount_paid' => '0.00',
    ]);
    expect(fn () => $service->allocate($payment, $invoice->getKey(), 60.0))
        ->toThrow(DomainException::class, 'exceeds invoice outstanding');

    $service->allocate($payment, $invoice->getKey(), 10.0);
    expect(fn () => $service->allocate($payment, $invoice->getKey(), 5.0))
        ->toThrow(DomainException::class, 'only once');
});
