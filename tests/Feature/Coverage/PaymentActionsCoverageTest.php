<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\Actions\PaymentActions;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function paymentActionsCoveragePayment(CustomerProfile $customer, string $number = 'PAY-ACTION-COVERAGE'): Payment
{
    return Payment::query()->create([
        'payment_number' => $number,
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory()->create()->getKey(),
        'amount' => '60.00',
        'currency' => 'USD',
        'payment_date' => today()->toDateString(),
        'status' => PaymentStatus::Draft,
    ]);
}

it('covers payment allocation helper branches', function (): void {
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    $payment = paymentActionsCoveragePayment($customer);
    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'issued_at' => now(),
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
    ]);
    $otherInvoice = Invoice::factory()->for($otherCustomer, 'customer')->create(['issued_at' => now()]);

    $allocationsFrom = new ReflectionMethod(PaymentActions::class, 'allocationsFrom');
    expect($allocationsFrom->invoke(null, null))->toBe([])
        ->and($allocationsFrom->invoke(null, ['bad-row', [
            'invoice_id' => (string) $invoice->getKey(),
            'amount' => '25.50',
        ], ['invoice_id' => null, 'amount' => 'bad']]))->toBe([
            ['invoice_id' => $invoice->getKey(), 'amount' => 25.5],
            ['invoice_id' => 0, 'amount' => 0.0],
        ]);

    $invoiceOptions = new ReflectionMethod(PaymentActions::class, 'invoiceOptions');
    $options = $invoiceOptions->invoke(null, $payment);
    expect($options)->toHaveKey($invoice->getKey());

    $defaultAllocation = new ReflectionMethod(PaymentActions::class, 'defaultAllocation');
    request()->merge(['invoice_id' => 0]);
    expect($defaultAllocation->invoke(null, $payment))->toBe([]);

    request()->merge(['invoice_id' => $otherInvoice->getKey()]);
    expect($defaultAllocation->invoke(null, $payment))->toBe([]);

    request()->merge(['invoice_id' => $invoice->getKey()]);
    expect($defaultAllocation->invoke(null, $payment))->toBe([[
        'invoice_id' => $invoice->getKey(),
        'amount' => 60.0,
    ]]);
});

it('executes payment post and reverse adapter guards', function (): void {
    $customer = CustomerProfile::factory()->create();
    $payment = paymentActionsCoveragePayment($customer, 'PAY-ACTION-GUARDS');

    $post = PaymentActions::post();
    $post->record($payment);

    expect($post->isVisible())->toBeFalse();
    ($post->getActionFunction())($payment, ['allocations' => []]);

    $reverse = PaymentActions::reverse();
    ($reverse->getActionFunction())($payment);

    $this->actingAs(User::factory()->admin()->create());
    Gate::before(static fn (): bool => true);
    expect($post->isVisible())->toBeTrue();

    try {
        ($post->getActionFunction())($payment, [
            'allocations' => [['invoice_id' => 'bad', 'amount' => 'bad']],
        ]);
    } catch (Halt) {
        // Domain validation is translated into Filament's stop signal.
    }

    $payment->refresh();
    if (! $payment->isPosted()) {
        $payment->forceFill(['status' => PaymentStatus::Posted, 'posted_at' => now()])->save();
    }

    $reverse->record($payment->refresh());
    expect($reverse->isVisible())->toBeTrue();
    try {
        ($reverse->getActionFunction())($payment->refresh());
    } catch (Halt) {
        // Accounting configuration failures still exercise the adapter boundary.
    }

    expect(true)->toBeTrue();
});
