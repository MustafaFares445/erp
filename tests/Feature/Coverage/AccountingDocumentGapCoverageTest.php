<?php

declare(strict_types=1);

use App\Enums\BillStatus;
use App\Enums\ExpenseStatus;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ChartAccount;
use App\Models\Expense;
use App\Models\FiscalPeriod;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    (new ChartOfAccountsSeeder)->run();
    FiscalPeriod::factory()->forMonth(CarbonImmutable::parse('2026-08-01'))->create();
    $this->actor = User::factory()->create();
    $this->service = app(AccountingDocumentService::class);
    $this->expenseAccount = ChartAccount::query()->where('code', '5300')->sole();
    $this->cashAccount = ChartAccount::query()->where('code', '1110')->sole();
});

function accountingGapMethod(string $name): ReflectionMethod
{
    return new ReflectionMethod(AccountingDocumentService::class, $name);
}

it('records expense and bill lines through the document service', function (): void {
    $supplier = Supplier::factory()->create();

    $expense = $this->service->recordExpense($this->actor, [
        'expense_number' => 'EXP-COV-001',
        'supplier_id' => $supplier->getKey(),
        'expense_account_id' => $this->expenseAccount->getKey(),
        'expense_date' => '2026-08-10',
        'due_date' => '2026-08-30',
        'merchant_name' => 'Coverage merchant',
        'description' => 'Coverage expense',
        'subtotal' => '10.00',
        'tax_total' => '0.00',
        'total_amount' => '10.00',
        'amount_paid' => '0.00',
        'status' => ExpenseStatus::Draft,
    ]);

    expect($expense->exists)->toBeTrue()
        ->and($expense->status)->toBe(ExpenseStatus::Draft);

    $bill = $this->service->recordBill($this->actor, [
        'bill_number' => 'BILL-COV-LINE-001',
        'supplier_id' => $supplier->getKey(),
        'supplier_reference' => 'SUP-COV-LINE-001',
        'bill_date' => '2026-08-10',
        'due_date' => '2026-08-30',
        'description' => 'Coverage bill',
        'subtotal' => '12.00',
        'tax_total' => '0.00',
        'total_amount' => '12.00',
        'amount_paid' => '0.00',
        'status' => BillStatus::Draft,
    ], [[
        'chart_account_id' => $this->expenseAccount->getKey(),
        'description' => 'Coverage line',
        'quantity' => 2,
        'unit_price' => 6,
        'tax_amount' => 0,
    ]]);

    expect($bill->lines()->count())->toBe(1)
        ->and($bill->lines()->sole()->line_total)->toBe('12.00');
});

it('covers bill and allocation validation guards', function (): void {
    $missingSupplier = new Bill;
    $missingSupplier->forceFill(['id' => 999999999]);

    expect(fn (): Bill => $this->service->approveBill($this->actor, $missingSupplier))
        ->toThrow(DomainException::class, 'requires a supplier');

    $normalizeReference = accountingGapMethod('normalizeSupplierReference');
    $tooLong = new Bill(['supplier_reference' => str_repeat('X', 101)]);
    expect(fn (): mixed => $normalizeReference->invoke($this->service, $tooLong))
        ->toThrow(DomainException::class, 'may not exceed 100');

    $normalizeAllocations = accountingGapMethod('normalizeAllocations');
    expect(fn (): mixed => $normalizeAllocations->invoke($this->service, [[
        'bill_id' => 0,
        'amount' => 10,
    ]]))->toThrow(DomainException::class, 'positive bill and amount');

    $inventoryAccount = ChartAccount::query()->where('code', '1300')->sole();
    $notInventory = accountingGapMethod('assertNotInventoryAccount');
    expect(fn (): mixed => $notInventory->invoke($this->service, $inventoryAccount->getKey()))
        ->toThrow(DomainException::class, 'Inventory account');
});

it('covers bill line amount totals and purchase-order reference guards', function (): void {
    $supplier = Supplier::factory()->create();
    $bill = Bill::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'subtotal' => '10.00',
        'tax_total' => '0.00',
        'total_amount' => '10.00',
    ]);

    $line = new BillLine([
        'quantity' => '2.000',
        'unit_price' => '5.00',
        'tax_amount' => '0.00',
        'line_total' => '9.00',
        'chart_account_id' => $this->expenseAccount->getKey(),
    ]);
    $line->id = 123;

    $assertLines = accountingGapMethod('assertBillLines');
    expect(fn (): mixed => $assertLines->invoke($this->service, $bill, new Collection([$line])))
        ->toThrow(DomainException::class, 'has net');

    $line->line_total = '0.00';
    $line->quantity = '0.000';

    expect(fn (): mixed => $assertLines->invoke($this->service, $bill, new Collection([$line])))
        ->toThrow(DomainException::class, 'invalid line amount');

    $line->quantity = '1.000';
    $line->unit_price = '10.00';
    $line->line_total = '10.00';

    $bill->subtotal = '9.00';
    expect(fn (): mixed => $assertLines->invoke($this->service, $bill, new Collection([$line])))
        ->toThrow(DomainException::class, 'states subtotal');

    $poA = PurchaseOrder::factory()->accepted()->create(['supplier_id' => $supplier->getKey()]);
    $poB = PurchaseOrder::factory()->accepted()->create(['supplier_id' => $supplier->getKey()]);
    $poLine = PurchaseOrderLine::factory()->for($poB)->create();

    $poBill = Bill::factory()->forPurchaseOrder($poA)->create();
    $poBillLine = BillLine::factory()->for($poBill)->create([
        'purchase_order_line_id' => $poLine->getKey(),
    ]);
    $poBillLine->setRelation('purchaseOrderLine', $poLine);

    $assertPoLine = accountingGapMethod('assertPurchaseOrderLineReference');
    expect(fn (): mixed => $assertPoLine->invoke($this->service, $poBill, $poBillLine))
        ->toThrow(DomainException::class, 'outside its purchase order');
});

it('covers supplier-payment validation and partial-payment branch', function (): void {
    $supplier = Supplier::factory()->create();
    $otherSupplier = Supplier::factory()->create();
    $activeMethod = PaymentMethod::factory()->create([
        'chart_account_id' => $this->cashAccount->getKey(),
        'is_active' => true,
    ]);

    $mismatch = SupplierPayment::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'payment_method_id' => $activeMethod->getKey(),
        'amount' => '100.00',
        'payment_date' => '2026-08-15',
    ]);
    expect(fn (): SupplierPayment => $this->service->paySupplierPayment($this->actor, $mismatch, [[
        'bill_id' => 999999,
        'amount' => '50.00',
    ]]))->toThrow(DomainException::class, 'allocations total');

    $missingBill = SupplierPayment::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'payment_method_id' => $activeMethod->getKey(),
        'amount' => '100.00',
        'payment_date' => '2026-08-15',
    ]);
    expect(fn (): SupplierPayment => $this->service->paySupplierPayment($this->actor, $missingBill, [[
        'bill_id' => 999999,
        'amount' => '100.00',
    ]]))->toThrow(DomainException::class, 'existing bill');

    $closedBill = Bill::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'status' => BillStatus::Cancelled,
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
    ]);
    $closedPayment = SupplierPayment::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'payment_method_id' => $activeMethod->getKey(),
        'amount' => '100.00',
        'payment_date' => '2026-08-15',
    ]);
    expect(fn (): SupplierPayment => $this->service->paySupplierPayment($this->actor, $closedPayment, [[
        'bill_id' => $closedBill->getKey(),
        'amount' => '100.00',
    ]]))->toThrow(DomainException::class, 'not open');

    $otherBill = Bill::factory()->create([
        'supplier_id' => $otherSupplier->getKey(),
        'status' => BillStatus::Approved,
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
    ]);
    $otherPayment = SupplierPayment::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'payment_method_id' => $activeMethod->getKey(),
        'amount' => '100.00',
        'payment_date' => '2026-08-15',
    ]);
    expect(fn (): SupplierPayment => $this->service->paySupplierPayment($this->actor, $otherPayment, [[
        'bill_id' => $otherBill->getKey(),
        'amount' => '100.00',
    ]]))->toThrow(DomainException::class, 'different supplier');

    $smallBill = Bill::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'status' => BillStatus::Approved,
        'subtotal' => '50.00',
        'tax_total' => '0.00',
        'total_amount' => '50.00',
        'amount_paid' => '0.00',
    ]);
    $largePayment = SupplierPayment::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'payment_method_id' => $activeMethod->getKey(),
        'amount' => '100.00',
        'payment_date' => '2026-08-15',
    ]);
    expect(fn (): SupplierPayment => $this->service->paySupplierPayment($this->actor, $largePayment, [[
        'bill_id' => $smallBill->getKey(),
        'amount' => '100.00',
    ]]))->toThrow(DomainException::class, 'exceeds bill');

    $inactiveMethod = PaymentMethod::factory()->create([
        'chart_account_id' => $this->cashAccount->getKey(),
        'is_active' => false,
    ]);
    $validBillForInactive = Bill::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'status' => BillStatus::Approved,
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
    ]);
    $inactivePayment = SupplierPayment::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'payment_method_id' => $inactiveMethod->getKey(),
        'amount' => '100.00',
        'payment_date' => '2026-08-15',
    ]);
    expect(fn (): SupplierPayment => $this->service->paySupplierPayment($this->actor, $inactivePayment, [[
        'bill_id' => $validBillForInactive->getKey(),
        'amount' => '100.00',
    ]]))->toThrow(DomainException::class, 'active payment method');

    $partialBill = Bill::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'status' => BillStatus::Approved,
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
    ]);
    $partialPayment = SupplierPayment::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'payment_method_id' => $activeMethod->getKey(),
        'amount' => '40.00',
        'payment_date' => '2026-08-15',
    ]);
    $this->service->paySupplierPayment($this->actor, $partialPayment, [[
        'bill_id' => $partialBill->getKey(),
        'amount' => '40.00',
    ]]);

    expect($partialBill->refresh()->status)->toBe(BillStatus::PartiallyPaid)
        ->and($partialBill->paid_amount)->toBe('40.00');
});

it('covers inactive expense payment method guard', function (): void {
    $inactiveMethod = PaymentMethod::factory()->create([
        'chart_account_id' => $this->cashAccount->getKey(),
        'is_active' => false,
    ]);
    $expense = Expense::factory()->create([
        'expense_account_id' => $this->expenseAccount->getKey(),
        'payment_method_id' => $inactiveMethod->getKey(),
        'status' => ExpenseStatus::Approved,
        'expense_date' => '2026-08-10',
        'subtotal' => '10.00',
        'tax_total' => '0.00',
        'total_amount' => '10.00',
    ]);

    expect(fn (): Expense => $this->service->payExpense($this->actor, $expense))
        ->toThrow(DomainException::class, 'active payment method');
});
