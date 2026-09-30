<?php

declare(strict_types=1);

use App\Enums\RefundStatus;
use App\Models\ChartAccount;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\JournalEntryLine;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Accounting\RefundService;
use App\Services\Payments\PaymentService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    (new CurrencySeeder)->run();
    (new ChartOfAccountsSeeder)->run();
    FiscalPeriod::factory()->create();

    $account = static fn (string $code): int => (int) ChartAccount::query()->where('code', $code)->sole()->getKey();

    SalesSetting::query()->create([
        'default_tax_percent' => '0.00',
        'default_quotation_validity_days' => 30,
        'receivable_account_id' => $account('1200'),
        'revenue_account_id' => $account('4100'),
        'deferred_tax_account_id' => $account('2350'),
        'tax_payable_account_id' => $account('2300'),
        'customer_deposits_account_id' => $account('2400'),
        'bad_debt_expense_account_id' => $account('6800'),
    ]);

    $this->actor = User::factory()->admin()->create();
    $this->customer = CustomerProfile::factory()->create();
    $this->method = PaymentMethod::factory()->create([
        'is_active' => true,
        'requires_proof' => false,
        'chart_account_id' => $account('1100'),
    ]);
});

it('snapshots and debits customer deposits for an unapplied-deposit refund', function (): void {
    $payments = app(PaymentService::class);
    $draftPayment = $payments->createDraft($this->actor, [
        'customer_id' => $this->customer->getKey(),
        'payment_method_id' => $this->method->getKey(),
        'amount' => '100.00',
        'currency' => 'AED',
        'payment_date' => today()->toDateString(),
    ]);
    $payments->post($this->actor, $draftPayment, []);

    $refund = Refund::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'payment_method_id' => $this->method->getKey(),
        'credit_note_id' => null,
        'invoice_id' => null,
        'amount' => '100.00',
        'refund_date' => today(),
        'status' => RefundStatus::Draft,
    ]);

    $service = app(RefundService::class);
    $approved = $service->approve($this->actor, $refund);

    expect($approved->customer_deposit_amount)->toBe('100.00');

    $paid = $service->pay($this->actor, $approved);
    $lines = JournalEntryLine::query()
        ->where('journal_entry_id', $paid->journal_entry_id)
        ->get();

    $deposits = ChartAccount::query()->where('code', '2400')->sole();
    $receivable = ChartAccount::query()->where('code', '1200')->sole();

    expect((float) $lines->firstWhere('chart_account_id', $deposits->getKey())?->debit)->toBe(100.0)
        ->and($lines->firstWhere('chart_account_id', $receivable->getKey()))->toBeNull();
});

it('keeps no-source standalone credit refunds compatible and assigns no deposit portion', function (): void {
    CreditNote::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'invoice_id' => null,
        'status' => 'confirmed',
        'confirmed_at' => now(),
        'grand_total' => '75.00',
    ]);

    $refund = Refund::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'payment_method_id' => $this->method->getKey(),
        'credit_note_id' => null,
        'invoice_id' => null,
        'amount' => '50.00',
        'refund_date' => today(),
        'status' => RefundStatus::Draft,
    ]);

    $approved = app(RefundService::class)->approve($this->actor, $refund);

    expect($approved->status)->toBe(RefundStatus::Approved)
        ->and($approved->customer_deposit_amount)->toBe('0.00');
});
