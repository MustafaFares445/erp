<?php

declare(strict_types=1);

use App\Data\Accounting\WriteOffData;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\WriteOffReason;
use App\Enums\WriteOffStatus;
use App\Exceptions\Domain\IllegalStatusTransition;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ReceivableWriteOff;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Accounting\Exceptions\ClosedFiscalPeriod;
use App\Services\Accounting\Exceptions\NoFiscalPeriodForDate;
use App\Services\Accounting\ReceivableWriteOffService;
use App\Services\Accounting\WriteOffPostingService;
use App\Services\Payments\PaymentAllocationService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);

    (new ChartOfAccountsSeeder)->run();

    $this->period = FiscalPeriod::factory()->create();
    $this->customer = CustomerProfile::factory()->create();
    $this->recorder = User::factory()->create();
    $this->approver = User::factory()->create();

    SalesSetting::query()->create([
        'default_tax_percent' => '0.00',
        'default_quotation_validity_days' => 30,
        'receivable_account_id' => ChartAccount::query()->where('code', '1200')->sole()->getKey(),
        'revenue_account_id' => ChartAccount::query()->where('code', '4100')->sole()->getKey(),
        'deferred_tax_account_id' => ChartAccount::query()->where('code', '2350')->sole()->getKey(),
        'tax_payable_account_id' => ChartAccount::query()->where('code', '2300')->sole()->getKey(),
        'customer_deposits_account_id' => ChartAccount::query()->where('code', '2400')->sole()->getKey(),
        'bad_debt_expense_account_id' => ChartAccount::query()->where('code', '6800')->sole()->getKey(),
    ]);
});

function writeOffTestInvoice(CustomerProfile $customer, array $overrides = []): Invoice
{
    return Invoice::factory()->create(array_merge([
        'customer_id' => $customer->getKey(),
        'status' => InvoiceStatus::Sent,
        'issued_at' => now()->subDay(),
        'sent_at' => now()->subHour(),
        'subtotal' => '100.00',
        'tax_total' => '10.00',
        'total_amount' => '110.00',
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
        'recognised_tax_amount' => '0.00',
    ], $overrides));
}

function writeOffDataFor(Invoice $invoice, int $amountMinor): WriteOffData
{
    return new WriteOffData(
        customerId: (int) $invoice->customer_id,
        invoiceId: (int) $invoice->getKey(),
        amountMinor: $amountMinor,
        reasonCategory: WriteOffReason::CommerciallyUneconomic,
        reason: 'Collection is no longer commercially economic.',
    );
}

it('records a draft against an issued invoice and posts nothing', function (): void {
    $invoice = writeOffTestInvoice($this->customer);

    $writeOff = app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 11_000),
        $this->recorder,
    );

    expect($writeOff->status)->toBe(WriteOffStatus::Draft)
        ->and($writeOff->amount_minor)->toBe(11_000)
        ->and($writeOff->recorded_by)->toBe($this->recorder->getKey())
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('refuses zero, excessive, cancelled, and already-written-off receivables', function (): void {
    $invoice = writeOffTestInvoice($this->customer);

    expect(fn () => app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 0),
        $this->recorder,
    ))->toThrow(DomainException::class);

    expect(fn () => app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 11_001),
        $this->recorder,
    ))->toThrow(DomainException::class);

    $invoice->forceFill(['status' => InvoiceStatus::Cancelled])->save();

    expect(fn () => app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice->fresh(), 1_000),
        $this->recorder,
    ))->toThrow(DomainException::class);

    $writtenOff = writeOffTestInvoice($this->customer);
    $writtenOff->forceFill(['status' => InvoiceStatus::WrittenOff])->save();

    expect(fn () => app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($writtenOff->fresh(), 1_000),
        $this->recorder,
    ))->toThrow(DomainException::class);
});

it('refuses self approval even when authorization is otherwise granted', function (): void {
    $invoice = writeOffTestInvoice($this->customer);
    $writeOff = app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 11_000),
        $this->recorder,
    );

    expect(fn () => app(ReceivableWriteOffService::class)->approve($writeOff, $this->recorder))
        ->toThrow(DomainException::class, 'The user who recorded a receivable write-off cannot approve it.');

    expect($writeOff->fresh()?->status)->toBe(WriteOffStatus::Draft)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('posts bad debt and deferred tax against AR without touching tax payable', function (): void {
    $invoice = writeOffTestInvoice($this->customer);
    $writeOff = app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 11_000),
        $this->recorder,
    );

    $approved = app(ReceivableWriteOffService::class)->approve($writeOff, $this->approver);
    $entry = $approved->journalEntry()->with('lines.chartAccount')->sole();

    $byCode = $entry->lines->keyBy(fn ($line): string => (string) $line->chartAccount?->code);

    expect($approved->status)->toBe(WriteOffStatus::Approved)
        ->and($approved->tax_amount_minor)->toBe(1_000)
        ->and($invoice->fresh()?->status)->toBe(InvoiceStatus::WrittenOff)
        ->and($invoice->fresh()?->outstandingMinor())->toBe(0)
        ->and($byCode->get('6800')?->debit)->toBe('100.00')
        ->and($byCode->get('2350')?->debit)->toBe('10.00')
        ->and($byCode->get('1200')?->credit)->toBe('110.00')
        ->and($byCode->has('2300'))->toBeFalse();
});

it('leaves a partial write off outstanding and still collectable', function (): void {
    $invoice = writeOffTestInvoice($this->customer);
    $writeOff = app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 5_500),
        $this->recorder,
    );

    app(ReceivableWriteOffService::class)->approve($writeOff, $this->approver);

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::WrittenOff)
        ->and($invoice->outstandingMinor())->toBe(5_500)
        ->and($invoice->isIssued())->toBeTrue();

    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-AFTER-WRITEOFF',
        'customer_id' => $invoice->customer_id,
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '10.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => today(),
        'status' => PaymentStatus::Draft,
    ]);

    app(PaymentAllocationService::class)->allocate($payment, (int) $invoice->getKey(), 10.00);

    expect($invoice->fresh()?->outstandingMinor())->toBe(4_500);
});

it('rechecks live outstanding under lock and refuses money collected after recording', function (): void {
    $invoice = writeOffTestInvoice($this->customer);
    $writeOff = app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 11_000),
        $this->recorder,
    );

    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-BETWEEN-RECORD-APPROVE',
        'customer_id' => $invoice->customer_id,
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '10.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => today(),
        'status' => PaymentStatus::Draft,
    ]);
    app(PaymentAllocationService::class)->allocate($payment, (int) $invoice->getKey(), 10.00);

    expect(fn () => app(ReceivableWriteOffService::class)->approve($writeOff, $this->approver))
        ->toThrow(DomainException::class, 'The write-off amount exceeds the invoice outstanding balance.');

    expect($writeOff->fresh()?->status)->toBe(WriteOffStatus::Draft)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('refuses approval when the write-off fiscal period closed after recording', function (): void {
    $invoice = writeOffTestInvoice($this->customer);
    $writeOff = app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 11_000),
        $this->recorder,
    );

    $this->period->forceFill(['is_closed' => true])->save();

    expect(fn () => app(ReceivableWriteOffService::class)->approve($writeOff, $this->approver))
        ->toThrow(ClosedFiscalPeriod::class);

    expect($writeOff->fresh()?->status)->toBe(WriteOffStatus::Draft)
        ->and($invoice->fresh()?->status)->toBe(InvoiceStatus::Sent)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('keeps the approved posting immutable', function (): void {
    $invoice = writeOffTestInvoice($this->customer);
    $writeOff = app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 11_000),
        $this->recorder,
    );
    $approved = app(ReceivableWriteOffService::class)->approve($writeOff, $this->approver);
    $line = $approved->journalEntry?->lines()->firstOrFail();

    expect(fn () => $line->forceFill(['debit' => '999.99'])->save())
        ->toThrow(DomainException::class);
});

it('refuses a mismatched customer and a blank recording reason', function (): void {
    $invoice = writeOffTestInvoice($this->customer);
    $otherCustomer = CustomerProfile::factory()->create();

    $mismatched = new WriteOffData(
        customerId: (int) $otherCustomer->getKey(),
        invoiceId: (int) $invoice->getKey(),
        amountMinor: 1_000,
        reasonCategory: WriteOffReason::Other,
        reason: 'Mismatch regression.',
    );

    expect(fn () => app(ReceivableWriteOffService::class)->record($mismatched, $this->recorder))
        ->toThrow(DomainException::class, 'The write-off customer must match the invoice customer.');

    $blankReason = new WriteOffData(
        customerId: (int) $invoice->customer_id,
        invoiceId: (int) $invoice->getKey(),
        amountMinor: 1_000,
        reasonCategory: WriteOffReason::Other,
        reason: '   ',
    );

    expect(fn () => app(ReceivableWriteOffService::class)->record($blankReason, $this->recorder))
        ->toThrow(DomainException::class, 'A write-off reason is required.');
});

it('requires an issued invoice when recording a write off', function (): void {
    $invoice = writeOffTestInvoice($this->customer, [
        'status' => InvoiceStatus::Draft,
        'issued_at' => null,
        'sent_at' => null,
    ]);

    expect(fn () => app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 1_000),
        $this->recorder,
    ))->toThrow(DomainException::class, 'Only an issued invoice can be written off.');
});

it('cancels only a draft and requires a cancellation reason', function (): void {
    $invoice = writeOffTestInvoice($this->customer);
    $writeOff = app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 1_000),
        $this->recorder,
    );

    expect(fn () => app(ReceivableWriteOffService::class)->cancel($writeOff, $this->recorder, '   '))
        ->toThrow(DomainException::class, 'A cancellation reason is required.');

    $cancelled = app(ReceivableWriteOffService::class)->cancel(
        $writeOff,
        $this->recorder,
        'Collection resumed.',
    );

    expect($cancelled->status)->toBe(WriteOffStatus::Cancelled)
        ->and($invoice->fresh()?->status)->toBe(InvoiceStatus::Sent);

    expect(fn () => app(ReceivableWriteOffService::class)->cancel(
        $cancelled,
        $this->recorder,
        'Second cancellation.',
    ))->toThrow(IllegalStatusTransition::class);
});

it('keeps approved and cancelled write-off records immutable', function (): void {
    $invoice = writeOffTestInvoice($this->customer);
    $writeOff = app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 11_000),
        $this->recorder,
    );
    $approved = app(ReceivableWriteOffService::class)->approve($writeOff, $this->approver);

    expect($approved->isApproved())->toBeTrue()
        ->and(fn () => $approved->forceFill(['reason' => 'Changed after approval'])->save())
        ->toThrow(DomainException::class, 'An approved or cancelled write-off is immutable.')
        ->and(fn () => $approved->delete())
        ->toThrow(DomainException::class, 'An approved write-off cannot be deleted.');

    $otherInvoice = writeOffTestInvoice($this->customer);
    $cancelled = app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($otherInvoice, 1_000),
        $this->recorder,
    );
    $cancelled = app(ReceivableWriteOffService::class)->cancel(
        $cancelled,
        $this->recorder,
        'No longer required.',
    );

    expect(fn () => $cancelled->forceFill(['reason' => 'Changed after cancellation'])->save())
        ->toThrow(DomainException::class, 'An approved or cancelled write-off is immutable.');
});

it('refuses recording when no fiscal period contains today', function (): void {
    FiscalPeriod::query()->delete();
    $invoice = writeOffTestInvoice($this->customer);

    expect(fn () => app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 1_000),
        $this->recorder,
    ))->toThrow(NoFiscalPeriodForDate::class);
});

it('posts a tax-free write off without a deferred-tax line', function (): void {
    $invoice = writeOffTestInvoice($this->customer, [
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
    ]);

    $writeOff = app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 10_000),
        $this->recorder,
    );

    $approved = app(ReceivableWriteOffService::class)->approve($writeOff, $this->approver);
    $codes = $approved->journalEntry()
        ->with('lines.chartAccount')
        ->sole()
        ->lines
        ->map(fn ($line): ?string => $line->chartAccount?->code)
        ->filter()
        ->values()
        ->all();

    expect($approved->tax_amount_minor)->toBe(0)
        ->and($codes)->toContain('6800', '1200')
        ->and($codes)->not->toContain('2350', '2300');
});

it('rechecks customer and issued state again at approval time', function (): void {
    $invoice = writeOffTestInvoice($this->customer);
    $otherCustomer = CustomerProfile::factory()->create();

    $customerMismatch = ReceivableWriteOff::factory()->create([
        'customer_id' => $otherCustomer->getKey(),
        'invoice_id' => $invoice->getKey(),
        'amount_minor' => 1_000,
        'recorded_by' => $this->recorder->getKey(),
        'fiscal_period_id' => $this->period->getKey(),
    ]);

    expect(fn () => app(ReceivableWriteOffService::class)->approve(
        $customerMismatch,
        $this->approver,
    ))->toThrow(DomainException::class, 'The write-off customer must match the invoice customer.');

    $draftInvoice = writeOffTestInvoice($this->customer, [
        'status' => InvoiceStatus::Draft,
        'issued_at' => null,
        'sent_at' => null,
    ]);
    $unissued = ReceivableWriteOff::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'invoice_id' => $draftInvoice->getKey(),
        'amount_minor' => 1_000,
        'recorded_by' => $this->recorder->getKey(),
        'fiscal_period_id' => $this->period->getKey(),
    ]);

    expect(fn () => app(ReceivableWriteOffService::class)->approve(
        $unissued,
        $this->approver,
    ))->toThrow(DomainException::class, 'Only an issued invoice can be written off.');
});

it('rolls back a posting that resolves to a different fiscal period than the write-off document', function (): void {
    $invoice = writeOffTestInvoice($this->customer);
    $writeOff = app(ReceivableWriteOffService::class)->record(
        writeOffDataFor($invoice, 11_000),
        $this->recorder,
    );

    $nextMonth = now()->toImmutable()->addMonthNoOverflow()->startOfMonth();
    $otherPeriod = FiscalPeriod::factory()->forMonth($nextMonth)->create();
    $writeOff->forceFill(['fiscal_period_id' => $otherPeriod->getKey()])->save();

    $before = JournalEntry::query()->count();

    expect(fn () => app(ReceivableWriteOffService::class)->approve(
        $writeOff,
        $this->approver,
    ))->toThrow(DomainException::class, 'The write-off posting resolved to a different fiscal period than the recorded document.');

    expect(JournalEntry::query()->count())->toBe($before)
        ->and($writeOff->fresh()?->status)->toBe(WriteOffStatus::Draft)
        ->and($invoice->fresh()?->status)->toBe(InvoiceStatus::Sent);
});

it('refuses invalid posting amounts before touching accounting settings', function (int $amountMinor, int $taxMinor): void {
    $invoice = writeOffTestInvoice($this->customer);
    $writeOff = ReceivableWriteOff::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'invoice_id' => $invoice->getKey(),
        'amount_minor' => $amountMinor,
        'recorded_by' => $this->recorder->getKey(),
        'fiscal_period_id' => $this->period->getKey(),
    ]);

    expect(fn () => app(WriteOffPostingService::class)->post(
        $this->approver,
        $writeOff,
        $invoice,
        $taxMinor,
    ))->toThrow(DomainException::class, 'A receivable write-off requires a positive amount and a valid deferred-tax portion.');
})->with([
    'zero amount' => [0, 0],
    'negative tax' => [100, -1],
    'tax above amount' => [100, 101],
]);
