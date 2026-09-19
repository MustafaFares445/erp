<?php

declare(strict_types=1);

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Services\Payments\TaxRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('returns null when an invoice has no tax to recognise', function (): void {
    $invoice = Invoice::factory()->create([
        'tax_total' => '0.00',
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
        'recognised_tax_amount' => '0.00',
    ]);
    $allocation = new PaymentAllocation;
    $allocation->forceFill([
        'invoice_id' => $invoice->getKey(),
        'amount' => '25.00',
    ]);
    $payment = new Payment;
    $payment->forceFill(['payment_date' => today()]);

    expect(app(TaxRecognitionService::class)->recognise(
        User::factory()->create(),
        $payment,
        $allocation,
    ))->toBeNull();
});

it('returns null when the invoice tax is already fully recognised', function (): void {
    $invoice = Invoice::factory()->create([
        'tax_total' => '10.00',
        'total_amount' => '100.00',
        'credited_amount' => '0.00',
        'amount_paid' => '0.00',
        'recognised_tax_amount' => '10.00',
    ]);
    $allocation = new PaymentAllocation;
    $allocation->forceFill([
        'invoice_id' => $invoice->getKey(),
        'amount' => '25.00',
    ]);
    $payment = new Payment;
    $payment->forceFill(['payment_date' => today()]);

    expect(app(TaxRecognitionService::class)->recognise(
        User::factory()->create(),
        $payment,
        $allocation,
    ))->toBeNull();
});

it('reverses tax recognition entries with and without journals and restores invoice tax', function (): void {
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();

    $plainInvoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'recognised_tax_amount' => '5.00',
    ]);
    $plainPayment = Payment::factory()->create([
        'payment_number' => 'PAY-TAX-COV-001',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '20.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => '2026-09-18',
    ]);
    TaxRecognitionEntry::factory()->create([
        'tax_date' => '2026-09-18',
        'direction' => 'output',
        'tax_type' => 'sales_tax',
        'tax_amount' => '5.00',
        'source_type' => Payment::class,
        'source_id' => $plainPayment->getKey(),
        'invoice_id' => $plainInvoice->getKey(),
        'payment_id' => $plainPayment->getKey(),
        'journal_entry_id' => null,
        'payment_amount' => '20.00',
        'recognised_tax_amount' => '5.00',
        'recognition_date' => '2026-09-18',
    ]);

    $journalInvoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'recognised_tax_amount' => '7.00',
    ]);
    $journalPayment = Payment::factory()->create([
        'payment_number' => 'PAY-TAX-COV-002',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '30.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => '2026-09-18',
    ]);
    $journal = JournalEntry::factory()->postedAndBalanced('7.00')->create();
    TaxRecognitionEntry::factory()->create([
        'tax_date' => '2026-09-18',
        'direction' => 'output',
        'tax_type' => 'sales_tax',
        'tax_amount' => '7.00',
        'source_type' => Payment::class,
        'source_id' => $journalPayment->getKey(),
        'invoice_id' => $journalInvoice->getKey(),
        'payment_id' => $journalPayment->getKey(),
        'journal_entry_id' => $journal->getKey(),
        'payment_amount' => '30.00',
        'recognised_tax_amount' => '7.00',
        'recognition_date' => '2026-09-18',
    ]);

    $service = app(TaxRecognitionService::class);
    $service->reverseForPayment($actor, $plainPayment);
    $service->reverseForPayment($actor, $journalPayment);

    expect((float) $plainInvoice->refresh()->recognised_tax_amount)->toBe(0.0)
        ->and((float) $journalInvoice->refresh()->recognised_tax_amount)->toBe(0.0)
        ->and(TaxRecognitionEntry::query()->where('direction', 'output_reversal')->count())->toBe(2)
        ->and(TaxRecognitionEntry::query()
            ->where('direction', 'output_reversal')
            ->whereNotNull('journal_entry_id')
            ->exists())->toBeTrue();
});
