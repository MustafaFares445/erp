<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\Bill;
use App\Models\ChartAccount;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\RefundService;
use App\Services\Accounting\TaxRegisterService;
use App\Services\Payments\PaymentService;
use App\Services\Payments\TaxRecognitionService;
use App\Services\Sales\CreditNotePostingService;
use App\Services\Sales\CreditNoteService;
use App\Services\Sales\InvoicePostingService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Accounting\ReceivablesReconciliationTest;

uses(RefreshDatabase::class);

/**
 * WP-2.7: derives the tax register — the deferred-versus-payable split that is
 * this company's tax policy — purely from posted documents, never from a
 * stored balance. Every scenario below mirrors a real posting path
 * ({@see InvoicePostingService}, {@see TaxRecognitionService},
 * {@see CreditNotePostingService}, {@see RefundService},
 * {@see AccountingDocumentService}) so the register is proven against the same
 * write paths production uses, exactly as
 * {@see ReceivablesReconciliationTest} does for the
 * receivable subledger.
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

    $this->recorder = User::factory()->admin()->create();
    $this->recorder->assignRole(DashboardRole::SystemAdmin->value);

    $this->approver = User::factory()->admin()->create();
    $this->approver->assignRole(DashboardRole::SystemAdmin->value);

    $this->customer = CustomerProfile::factory()->create();

    $this->paymentMethod = PaymentMethod::factory()->create([
        'chart_account_id' => ChartAccount::query()->where('code', '1110')->sole()->getKey(),
    ]);

    $this->expenseAccount = ChartAccount::query()->where('code', '5300')->sole();

    $this->register = app(TaxRegisterService::class);
});

function taxRegisterIssueInvoice(CarbonImmutable $date, int $customerId, User $actor, string $subtotal, string $tax, string $total): Invoice
{
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

function taxRegisterCollect(User $actor, int $customerId, int $paymentMethodId, Invoice $invoice, CarbonImmutable $date, string $amount): void
{
    $payment = app(PaymentService::class)->createDraft($actor, [
        'customer_id' => $customerId,
        'payment_method_id' => $paymentMethodId,
        'amount' => $amount,
        'payment_date' => $date->toDateString(),
    ]);

    app(PaymentService::class)->post($actor, $payment, [
        ['invoice_id' => $invoice->getKey(), 'amount' => $amount],
    ]);
}

it('increases deferred tax without recognising payable tax when an invoice is issued', function (): void {
    $today = CarbonImmutable::today();

    taxRegisterIssueInvoice($today, (int) $this->customer->getKey(), $this->actor, '1000.00', '100.00', '1100.00');

    $figures = $this->register->period($today, $today);

    expect($figures['output_tax_charged_deferred'])->toBe('100.00')
        ->and($figures['output_tax_recognised_payable'])->toBe('0.00')
        ->and($figures['output_tax_reversed'])->toBe('0.00')
        ->and($figures['input_tax_recognised'])->toBe('0.00')
        ->and($figures['net_position'])->toBe('0.00');
});

it('moves the proportional tax amount from deferred to payable when a payment collects part of the invoice', function (): void {
    $today = CarbonImmutable::today();

    $invoice = taxRegisterIssueInvoice($today, (int) $this->customer->getKey(), $this->actor, '800.00', '80.00', '880.00');
    taxRegisterCollect($this->actor, (int) $this->customer->getKey(), (int) $this->paymentMethod->getKey(), $invoice, $today, '440.00');

    $figures = $this->register->period($today, $today);

    expect($figures['output_tax_charged_deferred'])->toBe('80.00')
        ->and($figures['output_tax_recognised_payable'])->toBe('40.00')
        ->and($invoice->refresh()->recognised_tax_amount)->toBe('40.00');
});

it('reverses the correct deferred/payable split when a credit note is confirmed against a partially collected invoice', function (): void {
    $today = CarbonImmutable::today();

    $invoice = taxRegisterIssueInvoice($today, (int) $this->customer->getKey(), $this->actor, '800.00', '80.00', '880.00');
    taxRegisterCollect($this->actor, (int) $this->customer->getKey(), (int) $this->paymentMethod->getKey(), $invoice, $today, '440.00');

    $creditNote = CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $this->customer->getKey(),
        'issue_date' => $today->toDateString(),
    ]);
    app(CreditNoteService::class)->addLine($this->actor, $creditNote, 'Pricing correction', 1.0, 180.0, 20.0);
    app(CreditNoteService::class)->confirm($this->actor, $creditNote);

    $figures = $this->register->period($today, $today);

    // The invoice recognised half its tax (40.00 of 80.00), so the credit
    // note's 20.00 tax splits 10.00 recognised (payable) / 10.00 deferred —
    // proportional to that same 50% ratio, not an arbitrary allocation.
    expect($figures['output_tax_reversed'])->toBe('20.00');

    $reconciliation = $this->register->reconciliation($today, $today);

    expect($reconciliation['deferred']['difference'])->toBe('0.00')
        ->and($reconciliation['payable']['difference'])->toBe('0.00');
});

it('un-recognises tax proportionally when a refund is paid against a fully collected invoice', function (): void {
    $today = CarbonImmutable::today();

    $invoice = taxRegisterIssueInvoice($today, (int) $this->customer->getKey(), $this->actor, '1000.00', '100.00', '1100.00');
    taxRegisterCollect($this->actor, (int) $this->customer->getKey(), (int) $this->paymentMethod->getKey(), $invoice, $today, '1100.00');

    // A standalone (non-invoice) confirmed credit note frees customer credit
    // for a direct refund, without affecting any tax account itself (0 tax).
    $standaloneCredit = CreditNote::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'issue_date' => $today->toDateString(),
    ]);
    app(CreditNoteService::class)->addLine($this->actor, $standaloneCredit, 'Goodwill credit', 1.0, 550.0, 0.0);
    app(CreditNoteService::class)->confirm($this->actor, $standaloneCredit);

    $this->actingAs($this->recorder);
    $refund = Refund::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'invoice_id' => $invoice->getKey(),
        'credit_note_id' => null,
        'payment_method_id' => $this->paymentMethod->getKey(),
        'refund_date' => $today->toDateString(),
        'amount' => '550.00',
    ]);

    app(RefundService::class)->approve($this->approver, $refund);
    app(RefundService::class)->pay($this->approver, $refund->refresh());

    $figures = $this->register->period($today, $today);

    // Half the invoice's collected amount was refunded, so half its
    // recognised tax (50.00 of 100.00) is un-recognised back to deferred.
    expect($figures['output_tax_reversed'])->toBe('50.00');

    $reconciliation = $this->register->reconciliation($today, $today);

    expect($reconciliation['deferred']['difference'])->toBe('0.00')
        ->and($reconciliation['payable']['difference'])->toBe('0.00');
});

it('records recoverable input tax when a bill is approved', function (): void {
    $today = CarbonImmutable::today();

    $this->actingAs($this->recorder);
    $bill = Bill::factory()->create([
        'bill_date' => $today->toDateString(),
        'subtotal' => '500.00',
        'tax_total' => '25.00',
        'total_amount' => '525.00',
    ]);
    $bill->lines()->create([
        'chart_account_id' => $this->expenseAccount->getKey(),
        'description' => 'Consulting services',
        'quantity' => '1.000',
        'unit_price' => '500.00',
        'tax_amount' => '25.00',
        'line_total' => '500.00',
        'sort_order' => 1,
    ]);

    app(AccountingDocumentService::class)->approveBill($this->approver, $bill);

    $figures = $this->register->period($today, $today);

    expect($figures['input_tax_recognised'])->toBe('25.00');

    $reconciliation = $this->register->reconciliation($today, $today);

    expect($reconciliation['input']['difference'])->toBe('0.00');
});

it('reconciles the deferred, payable, and input tax accounts exactly for a realistic multi-document period', function (): void {
    $today = CarbonImmutable::today();
    $customerId = (int) $this->customer->getKey();
    $methodId = (int) $this->paymentMethod->getKey();

    // Invoice C: issued, fully collected, then partially credited.
    $invoiceC = taxRegisterIssueInvoice($today, $customerId, $this->actor, '2000.00', '200.00', '2200.00');
    taxRegisterCollect($this->actor, $customerId, $methodId, $invoiceC, $today, '2200.00');

    $creditNoteC = CreditNote::factory()->create([
        'invoice_id' => $invoiceC->getKey(),
        'customer_id' => $customerId,
        'issue_date' => $today->toDateString(),
    ]);
    app(CreditNoteService::class)->addLine($this->actor, $creditNoteC, 'Post-sale adjustment', 1.0, 180.0, 20.0);
    app(CreditNoteService::class)->confirm($this->actor, $creditNoteC);

    // Invoice D: issued, fully collected, then partially refunded via a
    // standalone credit that frees the customer's available balance.
    $invoiceD = taxRegisterIssueInvoice($today, $customerId, $this->actor, '1000.00', '100.00', '1100.00');
    taxRegisterCollect($this->actor, $customerId, $methodId, $invoiceD, $today, '1100.00');

    $standaloneCredit = CreditNote::factory()->create([
        'customer_id' => $customerId,
        'issue_date' => $today->toDateString(),
    ]);
    app(CreditNoteService::class)->addLine($this->actor, $standaloneCredit, 'Goodwill credit', 1.0, 550.0, 0.0);
    app(CreditNoteService::class)->confirm($this->actor, $standaloneCredit);

    $this->actingAs($this->recorder);
    $refund = Refund::factory()->create([
        'customer_id' => $customerId,
        'invoice_id' => $invoiceD->getKey(),
        'credit_note_id' => null,
        'payment_method_id' => $methodId,
        'refund_date' => $today->toDateString(),
        'amount' => '550.00',
    ]);
    app(RefundService::class)->approve($this->approver, $refund);
    app(RefundService::class)->pay($this->approver, $refund->refresh());

    // Bill: approved, recording recoverable input tax.
    $this->actingAs($this->recorder);
    $bill = Bill::factory()->create([
        'bill_date' => $today->toDateString(),
        'subtotal' => '500.00',
        'tax_total' => '25.00',
        'total_amount' => '525.00',
    ]);
    $bill->lines()->create([
        'chart_account_id' => $this->expenseAccount->getKey(),
        'description' => 'Consulting services',
        'quantity' => '1.000',
        'unit_price' => '500.00',
        'tax_amount' => '25.00',
        'line_total' => '500.00',
        'sort_order' => 1,
    ]);
    app(AccountingDocumentService::class)->approveBill($this->approver, $bill);

    $figures = $this->register->period($today, $today);

    expect($figures['output_tax_charged_deferred'])->toBe('300.00')
        ->and($figures['output_tax_recognised_payable'])->toBe('300.00')
        ->and($figures['output_tax_reversed'])->toBe('70.00')
        ->and($figures['input_tax_recognised'])->toBe('25.00')
        ->and($figures['net_position'])->toBe('205.00');

    $reconciliation = $this->register->reconciliation($today, $today);

    expect($reconciliation['deferred']['difference'])->toBe('0.00')
        ->and($reconciliation['payable']['difference'])->toBe('0.00')
        ->and($reconciliation['input']['difference'])->toBe('0.00');
});

it('reports a nonzero reconciliation difference when a manual journal entry posts directly to a tax account', function (): void {
    $today = CarbonImmutable::today();

    taxRegisterIssueInvoice($today, (int) $this->customer->getKey(), $this->actor, '500.00', '50.00', '550.00');

    expect($this->register->reconciliation($today, $today)['deferred']['difference'])->toBe('0.00');

    // A manual journal entry posted directly against the deferred tax
    // account, bypassing every tax-affecting document.
    $cash = ChartAccount::query()->where('code', '1110')->sole();
    $deferred = ChartAccount::query()->where('code', '2350')->sole();

    $entry = app(JournalPostingService::class)->draft(
        $this->actor,
        $today,
        [
            ['chart_account_id' => $cash->getKey(), 'debit' => '20.00', 'credit' => '0.00'],
            ['chart_account_id' => $deferred->getKey(), 'debit' => '0.00', 'credit' => '20.00'],
        ],
        'Out-of-band adjustment',
    );
    app(JournalPostingService::class)->post($this->actor, $entry);

    $reconciliation = $this->register->reconciliation($today, $today);

    expect($reconciliation['deferred']['difference'])->toBe('20.00')
        ->and($reconciliation['deferred']['difference'])->not->toBe('0.00');
});

it('places an invoice\'s deferred tax and its collection\'s payable tax in the correct adjoining fiscal periods', function (): void {
    $januaryEnd = CarbonImmutable::parse('2027-01-31');
    $februaryStart = CarbonImmutable::parse('2027-02-01');

    FiscalPeriod::factory()->between(
        CarbonImmutable::parse('2027-01-01'),
        $januaryEnd,
    )->create(['name' => 'January 2027']);
    FiscalPeriod::factory()->between(
        $februaryStart,
        CarbonImmutable::parse('2027-02-28'),
    )->create(['name' => 'February 2027']);

    $invoice = Invoice::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'invoice_date' => $januaryEnd->toDateString(),
        'due_date' => $februaryStart->addDays(30)->toDateString(),
        'subtotal' => '100.00',
        'tax_total' => '10.00',
        'total_amount' => '110.00',
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
        'status' => 'draft',
        'issued_at' => null,
    ]);
    app(InvoicePostingService::class)->post($this->actor, $invoice);
    $invoice->forceFill(['status' => 'sent', 'issued_at' => $januaryEnd, 'sent_at' => $januaryEnd])->save();

    taxRegisterCollect($this->actor, (int) $this->customer->getKey(), (int) $this->paymentMethod->getKey(), $invoice, $februaryStart, '110.00');

    $january = $this->register->period(CarbonImmutable::parse('2027-01-01'), $januaryEnd);
    $february = $this->register->period($februaryStart, CarbonImmutable::parse('2027-02-28'));

    expect($january['output_tax_charged_deferred'])->toBe('10.00')
        ->and($january['output_tax_recognised_payable'])->toBe('0.00')
        ->and($february['output_tax_charged_deferred'])->toBe('0.00')
        ->and($february['output_tax_recognised_payable'])->toBe('10.00');
});
