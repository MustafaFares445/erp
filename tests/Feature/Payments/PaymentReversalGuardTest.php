<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\ChartAccount;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Accounting\AccountBalanceService;
use App\Services\Payments\PaymentService;
use App\Services\Sales\CreditNoteService;
use App\Services\Sales\InvoicePostingService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A payment cannot be reversed once a later credit note or refund has already
 * moved the same cash and tax: reversing it would undo that money twice.
 */
beforeEach(function (): void {
    (new ChartOfAccountsSeeder)->run();
    (new AccountingPermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();

    SalesSetting::current()->forceFill([
        'receivable_account_id' => ChartAccount::query()->where('code', '1200')->sole()->getKey(),
        'revenue_account_id' => ChartAccount::query()->where('code', '4100')->sole()->getKey(),
        'deferred_tax_account_id' => ChartAccount::query()->where('code', '2350')->sole()->getKey(),
        'tax_payable_account_id' => ChartAccount::query()->where('code', '2300')->sole()->getKey(),
        'customer_deposits_account_id' => ChartAccount::query()->where('code', '2400')->sole()->getKey(),
        'bad_debt_expense_account_id' => ChartAccount::query()->where('code', '6800')->sole()->getKey(),
    ])->save();

    FiscalPeriod::factory()->create();

    $this->actor = User::factory()->admin()->create();
    $this->actor->assignRole(DashboardRole::SystemAdmin->value);

    $this->customer = CustomerProfile::factory()->create();
    $this->paymentMethod = PaymentMethod::factory()->create([
        'chart_account_id' => ChartAccount::query()->where('code', '1110')->sole()->getKey(),
    ]);
});

function reversalGuardInvoice(User $actor, CustomerProfile $customer): Invoice
{
    $date = CarbonImmutable::today();

    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_date' => $date->toDateString(),
        'due_date' => $date->addDays(30)->toDateString(),
        'subtotal' => '1000.00',
        'tax_total' => '100.00',
        'total_amount' => '1100.00',
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
        'status' => 'draft',
        'issued_at' => null,
    ]);

    app(InvoicePostingService::class)->post($actor, $invoice);
    $invoice->forceFill(['status' => 'sent', 'issued_at' => now(), 'sent_at' => now()])->save();

    return $invoice->refresh();
}

function reversalGuardPay(User $actor, CustomerProfile $customer, PaymentMethod $method, Invoice $invoice, string $paid, string $allocated): Payment
{
    $payment = app(PaymentService::class)->createDraft($actor, [
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => $paid,
        'payment_date' => CarbonImmutable::today()->toDateString(),
    ]);

    return app(PaymentService::class)->post($actor, $payment, [
        ['invoice_id' => $invoice->getKey(), 'amount' => $allocated],
    ]);
}

function reversalGuardCredit(User $actor, Invoice $invoice, string $net): CreditNote
{
    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $invoice->customer_id,
        'issue_date' => CarbonImmutable::today()->toDateString(),
    ]);

    app(CreditNoteService::class)->addLine($actor, $creditNote, 'Credit', 1.0, (float) $net, 0.0);

    return app(CreditNoteService::class)->confirm($actor, $creditNote);
}

function reversalGuardBalance(string $code): string
{
    return app(AccountBalanceService::class)->balanceFor(
        ChartAccount::query()->where('code', $code)->sole(),
        includeDescendants: false,
    );
}

it('refuses to reverse a payment after a later credit note on its invoice', function (): void {
    $invoice = reversalGuardInvoice($this->actor, $this->customer);
    $payment = reversalGuardPay($this->actor, $this->customer, $this->paymentMethod, $invoice, '1100.00', '1100.00');
    reversalGuardCredit($this->actor, $invoice, '550.00');

    expect(fn () => app(PaymentService::class)->reverse($this->actor, $payment))
        ->toThrow(DomainException::class, 'Reverse the later credit note or refund first');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Posted)
        ->and($invoice->refresh()->amount_paid)->toBe('1100.00');
});

it('refuses to reverse a payment after a later paid refund on its invoice', function (): void {
    $invoice = reversalGuardInvoice($this->actor, $this->customer);
    $payment = reversalGuardPay($this->actor, $this->customer, $this->paymentMethod, $invoice, '1100.00', '1100.00');

    Refund::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'invoice_id' => $invoice->getKey(),
        'amount' => '550.00',
        'status' => RefundStatus::Paid,
    ]);

    expect(fn () => app(PaymentService::class)->reverse($this->actor, $payment))
        ->toThrow(DomainException::class, 'Reverse the later credit note or refund first');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Posted)
        ->and(reversalGuardBalance('1110'))->toBe('1100.00');
});

it('refuses to reverse a payment after a later approved refund of a credit note on its invoice', function (): void {
    $this->travelTo(now()->subMinutes(5));
    $invoice = reversalGuardInvoice($this->actor, $this->customer);
    $creditNote = reversalGuardCredit($this->actor, $invoice, '100.00');
    $this->travelBack();

    $payment = reversalGuardPay($this->actor, $this->customer, $this->paymentMethod, $invoice, '1000.00', '1000.00');

    Refund::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'credit_note_id' => $creditNote->getKey(),
        'amount' => '100.00',
        'status' => RefundStatus::Approved,
    ]);

    expect(fn () => app(PaymentService::class)->reverse($this->actor, $payment))
        ->toThrow(DomainException::class, 'Reverse the later credit note or refund first');
});

it('refuses to reverse a payment whose deposit remainder was later refunded', function (): void {
    $invoice = reversalGuardInvoice($this->actor, $this->customer);
    $payment = reversalGuardPay($this->actor, $this->customer, $this->paymentMethod, $invoice, '1500.00', '1100.00');

    Refund::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'invoice_id' => null,
        'credit_note_id' => null,
        'amount' => '400.00',
        'customer_deposit_amount' => '400.00',
        'status' => RefundStatus::Paid,
    ]);

    expect(fn () => app(PaymentService::class)->reverse($this->actor, $payment))
        ->toThrow(DomainException::class, 'Reverse the later credit note or refund first');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Posted);
});

it('still reverses a plain payment and returns cash and recognised tax', function (): void {
    $invoice = reversalGuardInvoice($this->actor, $this->customer);
    $payment = reversalGuardPay($this->actor, $this->customer, $this->paymentMethod, $invoice, '1100.00', '1100.00');

    expect(reversalGuardBalance('2300'))->toBe('100.00');

    $reversed = app(PaymentService::class)->reverse($this->actor, $payment);

    expect($reversed->status)->toBe(PaymentStatus::Reversed)
        ->and(reversalGuardBalance('1110'))->toBe('0.00')
        ->and(reversalGuardBalance('2300'))->toBe('0.00')
        ->and(reversalGuardBalance('2350'))->toBe('100.00')
        ->and($invoice->refresh()->recognised_tax_amount)->toBe('0.00')
        ->and($invoice->amount_paid)->toBe('0.00');
});

it('still reverses a payment when the credit note predates it and only draft or cancelled refunds exist', function (): void {
    $this->travelTo(now()->subMinutes(5));
    $invoice = reversalGuardInvoice($this->actor, $this->customer);
    reversalGuardCredit($this->actor, $invoice, '100.00');
    $this->travelBack();

    $payment = reversalGuardPay($this->actor, $this->customer, $this->paymentMethod, $invoice, '1000.00', '1000.00');

    foreach ([RefundStatus::Draft, RefundStatus::Cancelled] as $status) {
        Refund::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount' => '50.00',
            'status' => $status,
        ]);
    }

    $reversed = app(PaymentService::class)->reverse($this->actor, $payment);

    expect($reversed->status)->toBe(PaymentStatus::Reversed)
        ->and(reversalGuardBalance('1110'))->toBe('0.00')
        ->and(reversalGuardBalance('2300'))->toBe('0.00')
        ->and($invoice->refresh()->recognised_tax_amount)->toBe('0.00');
});
