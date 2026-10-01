<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\ChartAccount;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\SalesSetting;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Services\Accounting\AccountBalanceService;
use App\Services\Accounting\TaxRegisterService;
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
 * Credit notes shrink the tax that can ever become payable. These scenarios pin
 * the invariant that the deferred tax account always ends at
 * `effective tax - recognised tax` (never negative) and that the payable tax
 * account equals the invoice's net recognised tax, however credit notes and
 * payments interleave.
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

function cnTaxIssueInvoice(User $actor, int $customerId, string $subtotal, string $tax, string $total): Invoice
{
    $date = CarbonImmutable::today();

    $invoice = Invoice::factory()->create([
        'customer_id' => $customerId,
        'invoice_date' => $date->toDateString(),
        'due_date' => $date->addDays(30)->toDateString(),
        'subtotal' => $subtotal,
        'tax_total' => $tax,
        'total_amount' => $total,
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
        'status' => 'draft',
        'issued_at' => null,
    ]);

    app(InvoicePostingService::class)->post($actor, $invoice);
    $invoice->forceFill(['status' => 'sent', 'issued_at' => now(), 'sent_at' => now()])->save();

    return $invoice->refresh();
}

function cnTaxPay(User $actor, int $customerId, int $methodId, Invoice $invoice, string $amount): void
{
    $payment = app(PaymentService::class)->createDraft($actor, [
        'customer_id' => $customerId,
        'payment_method_id' => $methodId,
        'amount' => $amount,
        'payment_date' => CarbonImmutable::today()->toDateString(),
    ]);

    app(PaymentService::class)->post($actor, $payment, [
        ['invoice_id' => $invoice->getKey(), 'amount' => $amount],
    ]);
}

function cnTaxCredit(User $actor, Invoice $invoice, string $net, string $tax): CreditNote
{
    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $invoice->customer_id,
        'issue_date' => CarbonImmutable::today()->toDateString(),
    ]);

    app(CreditNoteService::class)->addLine($actor, $creditNote, 'Correction', 1.0, (float) $net, (float) $tax);

    return app(CreditNoteService::class)->confirm($actor, $creditNote);
}

function cnTaxBalance(string $code): string
{
    return app(AccountBalanceService::class)->balanceFor(
        ChartAccount::query()->where('code', $code)->sole(),
        includeDescendants: false,
    );
}

function cnTaxAssertReconciled(): void
{
    $today = CarbonImmutable::today();
    $reconciliation = app(TaxRegisterService::class)->reconciliation($today, $today);

    expect($reconciliation['deferred']['difference'])->toBe('0.00')
        ->and($reconciliation['payable']['difference'])->toBe('0.00');
}

it('settles deferred tax to zero when the remaining claim is paid after a partial-paid credit note (repro A)', function (): void {
    $invoice = cnTaxIssueInvoice($this->actor, (int) $this->customer->getKey(), '800.00', '80.00', '880.00');
    cnTaxPay($this->actor, (int) $this->customer->getKey(), (int) $this->paymentMethod->getKey(), $invoice, '440.00');

    $creditNote = cnTaxCredit($this->actor, $invoice, '180.00', '20.00');

    // Target recognised after the credit: 60.00 x 440 / 680 = 38.82, so only
    // 1.18 of the credit's tax comes out of payable and 18.82 out of deferred.
    expect($creditNote->recognised_tax_portion)->toBe('1.18')
        ->and($invoice->refresh()->recognised_tax_amount)->toBe('38.82');

    cnTaxPay($this->actor, (int) $this->customer->getKey(), (int) $this->paymentMethod->getKey(), $invoice, '240.00');

    expect($invoice->refresh()->recognised_tax_amount)->toBe('60.00')
        ->and(cnTaxBalance('2300'))->toBe('60.00')
        ->and(cnTaxBalance('2350'))->toBe('0.00')
        ->and($invoice->outstandingAmount())->toBe(0.0);

    cnTaxAssertReconciled();
});

it('recognises only the effective tax when an unpaid invoice is credited and then paid (repro B)', function (): void {
    $invoice = cnTaxIssueInvoice($this->actor, (int) $this->customer->getKey(), '1000.00', '150.00', '1150.00');

    $creditNote = cnTaxCredit($this->actor, $invoice, '500.00', '75.00');

    expect($creditNote->recognised_tax_portion)->toBe('0.00')
        ->and(cnTaxBalance('2350'))->toBe('75.00')
        ->and(cnTaxBalance('2300'))->toBe('0.00');

    cnTaxPay($this->actor, (int) $this->customer->getKey(), (int) $this->paymentMethod->getKey(), $invoice, '575.00');

    expect($invoice->refresh()->recognised_tax_amount)->toBe('75.00')
        ->and(cnTaxBalance('2300'))->toBe('75.00')
        ->and(cnTaxBalance('2350'))->toBe('0.00');

    cnTaxAssertReconciled();
});

it('leaves no stuck deferred tax when a half-paid invoice is then credited for the unpaid half (repro C)', function (): void {
    $invoice = cnTaxIssueInvoice($this->actor, (int) $this->customer->getKey(), '1000.00', '150.00', '1150.00');
    cnTaxPay($this->actor, (int) $this->customer->getKey(), (int) $this->paymentMethod->getKey(), $invoice, '575.00');

    $creditNote = cnTaxCredit($this->actor, $invoice, '500.00', '75.00');

    expect($creditNote->recognised_tax_portion)->toBe('0.00')
        ->and($invoice->refresh()->recognised_tax_amount)->toBe('75.00')
        ->and($invoice->outstandingAmount())->toBe(0.0)
        ->and(cnTaxBalance('2300'))->toBe('75.00')
        ->and(cnTaxBalance('2350'))->toBe('0.00');

    cnTaxAssertReconciled();
});

it('moves the shortfall from deferred to payable when a tax-free credit leaves the invoice settled', function (): void {
    $invoice = cnTaxIssueInvoice($this->actor, (int) $this->customer->getKey(), '1000.00', '150.00', '1150.00');
    cnTaxPay($this->actor, (int) $this->customer->getKey(), (int) $this->paymentMethod->getKey(), $invoice, '575.00');

    $creditNote = cnTaxCredit($this->actor, $invoice, '575.00', '0.00');

    expect($creditNote->recognised_tax_portion)->toBe('0.00')
        ->and($invoice->refresh()->recognised_tax_amount)->toBe('150.00')
        ->and(cnTaxBalance('2300'))->toBe('150.00')
        ->and(cnTaxBalance('2350'))->toBe('0.00');

    $trueUp = TaxRecognitionEntry::query()
        ->where('source_type', CreditNote::class)
        ->where('source_id', $creditNote->getKey())
        ->sole();

    expect($trueUp->direction)->toBe('output')
        ->and($trueUp->recognised_tax_amount)->toBe('75.00')
        ->and($trueUp->journal_entry_id)->not->toBeNull();

    cnTaxAssertReconciled();
});

it('restores the recognised tax of the invoice when a credit note that took tax from payable is reversed', function (): void {
    $invoice = cnTaxIssueInvoice($this->actor, (int) $this->customer->getKey(), '800.00', '80.00', '880.00');
    cnTaxPay($this->actor, (int) $this->customer->getKey(), (int) $this->paymentMethod->getKey(), $invoice, '440.00');
    $creditNote = cnTaxCredit($this->actor, $invoice, '180.00', '20.00');

    app(CreditNoteService::class)->reverse($this->actor, $creditNote);

    expect($invoice->refresh()->recognised_tax_amount)->toBe('40.00')
        ->and($invoice->credited_amount)->toBe('0.00')
        ->and(cnTaxBalance('2300'))->toBe('40.00')
        ->and(cnTaxBalance('2350'))->toBe('40.00');

    cnTaxAssertReconciled();
});

it('reverses the recognition true-up of a credit note together with the note', function (): void {
    $invoice = cnTaxIssueInvoice($this->actor, (int) $this->customer->getKey(), '1000.00', '150.00', '1150.00');
    cnTaxPay($this->actor, (int) $this->customer->getKey(), (int) $this->paymentMethod->getKey(), $invoice, '575.00');
    $creditNote = cnTaxCredit($this->actor, $invoice, '575.00', '0.00');

    app(CreditNoteService::class)->reverse($this->actor, $creditNote);

    expect($invoice->refresh()->recognised_tax_amount)->toBe('75.00')
        ->and($invoice->credited_amount)->toBe('0.00')
        ->and(cnTaxBalance('2300'))->toBe('75.00')
        ->and(cnTaxBalance('2350'))->toBe('75.00')
        ->and(TaxRecognitionEntry::query()
            ->where('source_type', CreditNote::class)
            ->where('direction', 'output_reversal')
            ->sole()->recognised_tax_amount)->toBe('-75.00');

    cnTaxAssertReconciled();
});

it('returns all recognised tax to nothing when a partly paid invoice is credited in full', function (): void {
    $invoice = cnTaxIssueInvoice($this->actor, (int) $this->customer->getKey(), '1000.00', '150.00', '1150.00');
    cnTaxPay($this->actor, (int) $this->customer->getKey(), (int) $this->paymentMethod->getKey(), $invoice, '575.00');

    $creditNote = cnTaxCredit($this->actor, $invoice, '1000.00', '150.00');

    // Nothing of the invoice's tax remains owed, so the 75.00 that was payable
    // comes back out of payable and the other 75.00 out of deferred.
    expect($creditNote->recognised_tax_portion)->toBe('75.00')
        ->and($invoice->refresh()->recognised_tax_amount)->toBe('0.00')
        ->and(cnTaxBalance('2300'))->toBe('0.00')
        ->and(cnTaxBalance('2350'))->toBe('0.00');

    cnTaxAssertReconciled();
});

it('does not count a reversed credit note when prorating later tax recognition', function (): void {
    $invoice = cnTaxIssueInvoice($this->actor, (int) $this->customer->getKey(), '1000.00', '150.00', '1150.00');
    $creditNote = cnTaxCredit($this->actor, $invoice, '500.00', '75.00');
    app(CreditNoteService::class)->reverse($this->actor, $creditNote);

    cnTaxPay($this->actor, (int) $this->customer->getKey(), (int) $this->paymentMethod->getKey(), $invoice, '1150.00');

    expect($invoice->refresh()->recognised_tax_amount)->toBe('150.00')
        ->and(cnTaxBalance('2300'))->toBe('150.00')
        ->and(cnTaxBalance('2350'))->toBe('0.00');

    cnTaxAssertReconciled();
});
