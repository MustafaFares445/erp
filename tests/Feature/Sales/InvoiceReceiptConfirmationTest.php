<?php

declare(strict_types=1);

use App\Enums\InvoiceConfirmationType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\InvoiceConfirmation;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payments\PaymentAllocationService;
use App\Services\Sales\InvoiceBalanceService;
use App\Services\Sales\InvoiceConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    $this->actor = User::factory()->create();
});

function sentInvoiceForReceipt(): Invoice
{
    return Invoice::factory()->create([
        'status' => InvoiceStatus::Sent,
        'issued_at' => now()->subDay(),
        'sent_at' => now()->subHour(),
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
    ]);
}

it('records customer receipt evidence without changing the sent invoice lifecycle', function (): void {
    $invoice = sentInvoiceForReceipt();

    $confirmation = app(InvoiceConfirmationService::class)->confirm(
        $this->actor,
        $invoice,
        InvoiceConfirmationType::CustomerReceived,
        'Customer acknowledged receipt.',
    );

    $invoice->refresh();

    expect($confirmation->confirmation_type)->toBe(InvoiceConfirmationType::CustomerReceived)
        ->and($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->received_confirmation_type)->toBe(InvoiceConfirmationType::CustomerReceived)
        ->and($invoice->received_confirmed_at)->not->toBeNull()
        ->and($invoice->received_confirmed_by)->toBe($this->actor->getKey());
});

it('keeps confirmation history append only while the snapshot follows the latest confirmation', function (): void {
    $invoice = sentInvoiceForReceipt();

    app(InvoiceConfirmationService::class)->confirm(
        $this->actor,
        $invoice,
        InvoiceConfirmationType::CustomerReceived,
    );

    $secondActor = User::factory()->create();

    app(InvoiceConfirmationService::class)->confirm(
        $secondActor,
        $invoice->fresh(),
        InvoiceConfirmationType::EmployeeConfirmedReceived,
        'Employee recorded delivery evidence.',
    );

    $invoice->refresh();

    expect(InvoiceConfirmation::query()
        ->where('invoice_id', $invoice->getKey())
        ->count())->toBe(2)
        ->and($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->received_confirmation_type)->toBe(InvoiceConfirmationType::EmployeeConfirmedReceived)
        ->and($invoice->received_confirmed_by)->toBe($secondActor->getKey());
});

it('refuses receipt evidence before an invoice has been sent', function (): void {
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'sent_at' => null,
    ]);

    expect(fn () => app(InvoiceConfirmationService::class)->confirm(
        $this->actor,
        $invoice,
        InvoiceConfirmationType::CustomerReceived,
    ))->toThrow(DomainException::class, 'Only a sent invoice can record receipt evidence.');

    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->confirmations()->count())->toBe(0);
});

it('keeps payment and credit balance changes independent from invoice lifecycle', function (): void {
    $invoice = sentInvoiceForReceipt();

    app(InvoiceConfirmationService::class)->confirm(
        $this->actor,
        $invoice,
        InvoiceConfirmationType::CustomerReceived,
    );

    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-RECEIPT-001',
        'customer_id' => $invoice->customer_id,
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '25.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => today(),
        'status' => PaymentStatus::Draft,
    ]);

    app(PaymentAllocationService::class)->allocate(
        $payment,
        (int) $invoice->getKey(),
        25.00,
    );

    $invoice->refresh();

    expect($invoice->amount_paid)->toBe('25.00')
        ->and($invoice->status)->toBe(InvoiceStatus::Sent);

    $invoice->forceFill([
        'credited_amount' => '75.00',
        'amount_paid' => '25.00',
    ])->save();

    app(InvoiceBalanceService::class)->syncInvoice($invoice);

    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Sent)
        ->and(app(InvoiceBalanceService::class)->status($invoice->fresh()))->toBe('credited');
});
