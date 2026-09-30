<?php

declare(strict_types=1);

use App\Enums\RefundStatus;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\JournalEntryLine;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Accounting\RefundService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
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
        'chart_account_id' => $account('1100'),
    ]);
});

it('debits customer deposits instead of accounts receivable for an unapplied-credit refund', function (): void {
    $refund = Refund::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'payment_method_id' => $this->method->getKey(),
        'credit_note_id' => null,
        'invoice_id' => null,
        'amount' => '100.00',
        'refund_date' => today(),
        'status' => RefundStatus::Approved,
    ]);

    $paid = app(RefundService::class)->pay($this->actor, $refund);

    $lines = JournalEntryLine::query()
        ->where('journal_entry_id', $paid->journal_entry_id)
        ->get();

    $deposits = ChartAccount::query()->where('code', '2400')->sole();
    $receivable = ChartAccount::query()->where('code', '1200')->sole();

    expect((float) $lines->firstWhere('chart_account_id', $deposits->getKey())?->debit)->toBe(100.0)
        ->and($lines->firstWhere('chart_account_id', $receivable->getKey()))->toBeNull();
});
