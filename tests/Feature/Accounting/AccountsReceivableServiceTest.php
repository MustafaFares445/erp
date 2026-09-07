<?php

declare(strict_types=1);

use App\Enums\CreditNoteStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\PaymentStatus;
use App\Enums\WriteOffStatus;
use App\Models\ChartAccount;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ReceivableWriteOff;
use App\Models\SalesSetting;
use App\Services\Accounting\AccountsReceivableService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new ChartOfAccountsSeeder)->run();

    $this->period = FiscalPeriod::factory()->create();
    $this->customer = CustomerProfile::factory()->create([
        'company_name' => 'Northwind Dental',
    ]);

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

    $this->service = app(AccountsReceivableService::class);
});

function arInvoice(CustomerProfile $customer, CarbonImmutable $asOf, int $dueOffsetDays, string $amount = '100.00', InvoiceStatus $status = InvoiceStatus::Sent): Invoice
{
    return Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_date' => $asOf->subDays(120)->toDateString(),
        'due_date' => $asOf->addDays($dueOffsetDays)->toDateString(),
        'issued_at' => $asOf->subDays(120),
        'sent_at' => $asOf->subDays(119),
        'subtotal' => $amount,
        'tax_total' => '0.00',
        'total_amount' => $amount,
        'amount_paid' => '0.00',
        'status' => $status,
    ]);
}

function arPostControlEntry(Invoice $invoice, FiscalPeriod $period, string $amount, bool $normalSource = true): JournalEntry
{
    $entry = JournalEntry::factory()->create([
        'entry_date' => $invoice->invoice_date,
        'status' => JournalEntryStatus::Draft,
    ]);

    if ($normalSource) {
        $entry->forceFill([
            'source_type' => Invoice::class,
            'source_id' => $invoice->getKey(),
        ])->saveQuietly();
    }

    $receivable = ChartAccount::query()->where('code', '1200')->sole();
    $revenue = ChartAccount::query()->where('code', '4100')->sole();

    JournalEntryLine::factory()->for($entry)->create([
        'chart_account_id' => $receivable->getKey(),
        'debit' => $amount,
        'credit' => '0.00',
        'sort_order' => 1,
    ]);
    JournalEntryLine::factory()->for($entry)->create([
        'chart_account_id' => $revenue->getKey(),
        'debit' => '0.00',
        'credit' => $amount,
        'sort_order' => 2,
    ]);

    $entry->forceFill([
        'status' => JournalEntryStatus::Posted->value,
        'fiscal_period_id' => $period->getKey(),
    ])->saveQuietly();

    return $entry;
}

it('ages outstanding invoices into the five required buckets and excludes cancelled invoices', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-05 12:00:00');

    arInvoice($this->customer, $asOf, 5);
    arInvoice($this->customer, $asOf, -15);
    arInvoice($this->customer, $asOf, -45);
    arInvoice($this->customer, $asOf, -75);
    arInvoice($this->customer, $asOf, -120);
    arInvoice($this->customer, $asOf, -20, '999.00', InvoiceStatus::Cancelled);

    $summary = $this->service->aging($asOf);
    $customer = $summary['customers'][0];

    expect($summary['billed_minor'])->toBe(50_000)
        ->and($summary['outstanding_minor'])->toBe(50_000)
        ->and($customer['buckets'])->toBe([
            'current' => 10_000,
            '1_30' => 10_000,
            '31_60' => 10_000,
            '61_90' => 10_000,
            'over_90' => 10_000,
        ]);
});

it('derives outstanding from confirmed credits, posted payments, and approved write offs as of the report date', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-05 12:00:00');
    $invoice = arInvoice($this->customer, $asOf, -10);

    CreditNote::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $this->customer->getKey(),
        'subtotal' => '10.00',
        'tax_total' => '0.00',
        'grand_total' => '10.00',
        'status' => CreditNoteStatus::Confirmed,
        'confirmed_at' => $asOf->subDays(3),
    ]);

    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-AR-001',
        'customer_id' => $this->customer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '20.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => $asOf->subDays(2)->toDateString(),
        'status' => PaymentStatus::Posted,
        'posted_at' => $asOf->subDays(2),
    ]);
    $payment->allocations()->create([
        'invoice_id' => $invoice->getKey(),
        'amount' => '20.00',
    ]);

    ReceivableWriteOff::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'invoice_id' => $invoice->getKey(),
        'amount_minor' => 1_500,
        'status' => WriteOffStatus::Approved,
        'approved_at' => $asOf->subDay(),
        'fiscal_period_id' => $this->period->getKey(),
    ]);

    $summary = $this->service->aging($asOf);

    expect($summary['billed_minor'])->toBe(10_000)
        ->and($summary['credited_minor'])->toBe(1_000)
        ->and($summary['paid_minor'])->toBe(2_000)
        ->and($summary['written_off_minor'])->toBe(1_500)
        ->and($summary['outstanding_minor'])->toBe(5_500);
});

it('reconciles against the configured AR account rather than cash and surfaces direct control journals', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-05 12:00:00');
    $invoice = arInvoice($this->customer, $asOf, 10);
    arPostControlEntry($invoice, $this->period, '100.00');

    $clean = $this->service->reconciliation($asOf);

    expect($clean['subledger_minor'])->toBe(10_000)
        ->and($clean['control_account_minor'])->toBe(10_000)
        ->and($clean['difference_minor'])->toBe(0)
        ->and($clean['is_reconciled'])->toBeTrue();

    $direct = arInvoice($this->customer, $asOf, 20, '5.00');
    $direct->forceFill(['status' => InvoiceStatus::Cancelled])->saveQuietly();
    arPostControlEntry($direct, $this->period, '5.00', false);

    $mismatch = $this->service->reconciliation($asOf);

    expect($mismatch['subledger_minor'])->toBe(10_000)
        ->and($mismatch['control_account_minor'])->toBe(10_500)
        ->and($mismatch['difference_minor'])->toBe(-500)
        ->and($mismatch['is_reconciled'])->toBeFalse()
        ->and(collect($mismatch['candidate_causes'])->pluck('code')->all())
        ->toContain('direct_ar_journals');
});

it('exports the aging proof and control-account tie out as csv', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-05 12:00:00');
    arInvoice($this->customer, $asOf, -5);

    $csv = $this->service->toCsv($asOf);

    expect($csv)->toContain('Northwind Dental')
        ->and($csv)->toContain('Subledger outstanding')
        ->and($csv)->toContain('Receivable control account')
        ->and($csv)->toContain('Tie-out difference');
});
