<?php

declare(strict_types=1);

use App\Enums\BillStatus;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\SupplierPaymentStatus;
use App\Exceptions\Domain\IllegalStatusTransition;
use App\Models\Bill;
use App\Models\CustomerProfile;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use App\Services\Accounting\RefundService;
use App\Services\Payments\PaymentService;
use App\Services\Sales\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    $this->actor = User::factory()->create();
});

it('rejects illegal invoice issue through the service before posting', function (): void {
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
    ]);
    $before = JournalEntry::query()->count();

    expect(fn () => app(InvoiceService::class)->issue($this->actor, $invoice))
        ->toThrow(IllegalStatusTransition::class);

    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Issued)
        ->and(JournalEntry::query()->count())->toBe($before);
});

it('rejects illegal payment post through the service before posting', function (): void {
    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-ILLEGAL-001',
        'customer_id' => CustomerProfile::factory(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '10.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => today(),
        'status' => PaymentStatus::Posted,
        'posted_at' => now(),
    ]);
    $before = JournalEntry::query()->count();

    expect(fn () => app(PaymentService::class)->post($this->actor, $payment, []))
        ->toThrow(IllegalStatusTransition::class);

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Posted)
        ->and(JournalEntry::query()->count())->toBe($before);
});

it('rejects illegal bill approval through the service before posting', function (): void {
    $bill = Bill::factory()->create(['status' => BillStatus::Approved]);
    $before = JournalEntry::query()->count();

    expect(fn () => app(AccountingDocumentService::class)->approveBill($this->actor, $bill))
        ->toThrow(IllegalStatusTransition::class);

    expect($bill->fresh()?->status)->toBe(BillStatus::Approved)
        ->and(JournalEntry::query()->count())->toBe($before);
});

it('rejects illegal expense approval through the service before posting', function (): void {
    $expense = Expense::factory()->create(['status' => ExpenseStatus::Approved]);
    $before = JournalEntry::query()->count();

    expect(fn () => app(AccountingDocumentService::class)->approveExpense($this->actor, $expense))
        ->toThrow(IllegalStatusTransition::class);

    expect($expense->fresh()?->status)->toBe(ExpenseStatus::Approved)
        ->and(JournalEntry::query()->count())->toBe($before);
});

it('rejects illegal supplier payment execution through the service before posting', function (): void {
    $payment = SupplierPayment::factory()->create(['status' => SupplierPaymentStatus::Paid]);
    $before = JournalEntry::query()->count();

    expect(fn () => app(AccountingDocumentService::class)->paySupplierPayment($this->actor, $payment, []))
        ->toThrow(IllegalStatusTransition::class);

    expect($payment->fresh()?->status)->toBe(SupplierPaymentStatus::Paid)
        ->and(JournalEntry::query()->count())->toBe($before);
});

it('rejects illegal refund approval through the service before posting', function (): void {
    $refund = Refund::factory()->create(['status' => RefundStatus::Approved]);
    $before = JournalEntry::query()->count();

    expect(fn () => app(RefundService::class)->approve($this->actor, $refund))
        ->toThrow(IllegalStatusTransition::class);

    expect($refund->fresh()?->status)->toBe(RefundStatus::Approved)
        ->and(JournalEntry::query()->count())->toBe($before);
});

it('refuses unknown stored lifecycle values when enum casts hydrate', function (): void {
    $records = [
        Invoice::factory()->create(),
        Payment::factory()->create([
            'payment_number' => 'PAY-INVALID-001',
            'customer_id' => CustomerProfile::factory(),
            'payment_method_id' => PaymentMethod::factory(),
            'amount' => '10.00',
            'currency' => 'USD',
            'source' => 'manual',
            'payment_date' => today(),
            'status' => PaymentStatus::Draft,
        ]),
        Bill::factory()->create(),
        Expense::factory()->create(),
        SupplierPayment::factory()->create(),
        Refund::factory()->create(),
    ];

    foreach ($records as $record) {
        DB::table($record->getTable())
            ->where('id', $record->getKey())
            ->update(['status' => 'not_a_real_status']);

        $class = $record::class;
        $fresh = $class::query()->findOrFail($record->getKey());

        expect(fn () => $fresh->status)
            ->toThrow(ValueError::class, 'not_a_real_status');
    }
});
