<?php

declare(strict_types=1);

use App\Enums\CreditNoteStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\WriteOffStatus;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ReceivableWriteOff;
use App\Services\Accounting\AccountsReceivableService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers receivable customer detail overdue document mapping', function (): void {
    $customer = CustomerProfile::factory()->create(['company_name' => 'Coverage AR']);
    $asOf = CarbonImmutable::parse('2026-09-18 12:00:00');

    Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_date' => $asOf->subDays(60)->toDateString(),
        'due_date' => $asOf->subDays(10)->toDateString(),
        'issued_at' => $asOf->subDays(60),
        'subtotal' => '25.00',
        'tax_total' => '0.00',
        'total_amount' => '25.00',
        'status' => InvoiceStatus::Sent,
    ]);

    $detail = app(AccountsReceivableService::class)->customerDetail($customer, $asOf);

    expect($detail['documents'])->toHaveCount(1)
        ->and($detail['documents'][0]['days_overdue'])->toBe(10);
});

it('rejects an inverted receivable statement date range', function (): void {
    $customer = CustomerProfile::factory()->create();

    expect(fn () => app(AccountsReceivableService::class)->statement(
        $customer,
        CarbonImmutable::parse('2026-09-18'),
        CarbonImmutable::parse('2026-09-17'),
    ))->toThrow(LogicException::class, 'end date must not be before');
});
it('surfaces unposted and cancelled invoice allocation reconciliation causes', function (): void {
    $customer = CustomerProfile::factory()->create();
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => InvoiceStatus::Cancelled,
        'issued_at' => now()->subDay(),
    ]);
    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-AR-COV-001',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '10.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => now()->toDateString(),
        'status' => PaymentStatus::Draft,
        'posted_at' => null,
    ]);
    $payment->allocations()->create([
        'invoice_id' => $invoice->getKey(),
        'amount' => '10.00',
    ]);

    $causes = new ReflectionMethod(AccountsReceivableService::class, 'candidateCauses')
        ->invoke(app(AccountsReceivableService::class));

    expect(collect($causes)->pluck('code')->all())
        ->toContain('unposted_payments', 'cancelled_invoice_allocations');
});
it('covers receivable statement invoice payment credit and write off entries', function (): void {
    $customer = CustomerProfile::factory()->create(['company_name' => 'Statement Coverage']);
    $from = CarbonImmutable::parse('2026-09-01');
    $to = CarbonImmutable::parse('2026-09-30');
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_date' => '2026-09-02',
        'due_date' => '2026-09-15',
        'issued_at' => CarbonImmutable::parse('2026-09-02'),
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
        'status' => InvoiceStatus::Sent,
    ]);

    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-AR-COV-002',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '20.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => '2026-09-10',
        'status' => PaymentStatus::Posted,
        'posted_at' => CarbonImmutable::parse('2026-09-10'),
    ]);
    $payment->allocations()->create([
        'invoice_id' => $invoice->getKey(),
        'amount' => '20.00',
    ]);
    CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $customer->getKey(),
        'subtotal' => '5.00',
        'tax_total' => '0.00',
        'grand_total' => '5.00',
        'status' => CreditNoteStatus::Confirmed,
        'confirmed_at' => CarbonImmutable::parse('2026-09-12'),
    ]);

    ReceivableWriteOff::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_id' => $invoice->getKey(),
        'amount_minor' => 300,
        'status' => WriteOffStatus::Approved,
        'approved_at' => CarbonImmutable::parse('2026-09-15'),
        'fiscal_period_id' => FiscalPeriod::factory(),
    ]);

    $statement = app(AccountsReceivableService::class)->statement($customer, $from, $to);
    expect(collect($statement['entries'])->pluck('type')->all())
        ->toContain('invoice', 'payment', 'credit_note', 'write_off');
});
it('excludes reversed payments from customer statement entries', function (): void {
    $customer = CustomerProfile::factory()->create();
    $from = CarbonImmutable::parse('2026-09-01');
    $to = CarbonImmutable::parse('2026-09-30');
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'issued_at' => CarbonImmutable::parse('2026-09-02'),
        'status' => InvoiceStatus::Sent,
    ]);
    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-AR-REVERSED',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '20.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => '2026-09-10',
        'status' => PaymentStatus::Reversed,
        'posted_at' => CarbonImmutable::parse('2026-09-10'),
        'reversed_at' => CarbonImmutable::parse('2026-09-11'),
    ]);
    $payment->allocations()->create([
        'invoice_id' => $invoice->getKey(),
        'amount' => '20.00',
    ]);

    $statement = app(AccountsReceivableService::class)->statement($customer, $from, $to);

    expect(collect($statement['entries'])->where('reference', 'PAY-AR-REVERSED'))->toBeEmpty();
});
