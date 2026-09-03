<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\ChartAccount;
use App\Models\Expense;
use App\Models\FiscalPeriod;
use App\Models\InventoryOperation;
use App\Models\JournalEntry;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingDocumentService;
use App\Services\Accounting\AccountsPayableService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();
    (new ChartOfAccountsSeeder)->run();

    FiscalPeriod::factory()->forMonth(CarbonImmutable::parse('2026-08-01'))->create();

    $this->recorder = User::factory()->create();
    $this->recorder->assignRole(DashboardRole::Accountant->value);

    $this->approver = User::factory()->create();
    $this->approver->assignRole(DashboardRole::ChiefAccountant->value);

    $this->supplier = Supplier::factory()->create();
    $this->expenseAccount = ChartAccount::query()->where('code', '5300')->sole();
    $this->paymentMethod = PaymentMethod::factory()->create([
        'chart_account_id' => ChartAccount::query()->where('code', '1110')->sole()->getKey(),
    ]);
    $this->documents = app(AccountingDocumentService::class);
});

it('approves and pays an expense with two source-linked balanced entries', function (): void {
    $this->actingAs($this->recorder);
    $expense = Expense::factory()->create([
        'supplier_id' => $this->supplier->getKey(),
        'expense_account_id' => $this->expenseAccount->getKey(),
        'payment_method_id' => $this->paymentMethod->getKey(),
        'expense_date' => '2026-08-10',
        'subtotal' => '100.00',
        'tax_total' => '5.00',
        'total_amount' => '105.00',
    ]);

    $this->actingAs($this->approver);
    $approved = $this->documents->approveExpense($this->approver, $expense);

    $this->actingAs($this->recorder);
    $paid = $this->documents->payExpense($this->recorder, $approved);

    expect($paid->status)->toBe('paid')
        ->and(JournalEntry::query()->where('source_type', Expense::class)->count())->toBe(2)
        ->and($paid->outstandingAmount())->toBe(0.0);
});

it('approves a bill and atomically allocates a supplier payment across it', function (): void {
    $this->actingAs($this->recorder);
    $bill = Bill::factory()->create([
        'supplier_id' => $this->supplier->getKey(),
        'supplier_reference' => 'SUP-INV-001',
        'bill_date' => '2026-08-10',
        'subtotal' => '200.00',
        'tax_total' => '10.00',
        'total_amount' => '210.00',
    ]);
    $bill->lines()->create([
        'chart_account_id' => $this->expenseAccount->getKey(),
        'description' => 'Maintenance',
        'quantity' => '2.000',
        'unit_price' => '100.00',
        'tax_amount' => '10.00',
        'line_total' => '200.00',
        'sort_order' => 1,
    ]);

    $this->actingAs($this->approver);
    $approved = $this->documents->approveBill($this->approver, $bill);

    $this->actingAs($this->recorder);
    $payment = SupplierPayment::factory()->create([
        'supplier_id' => $this->supplier->getKey(),
        'payment_method_id' => $this->paymentMethod->getKey(),
        'amount' => '210.00',
        'payment_date' => '2026-08-15',
    ]);
    $paid = $this->documents->paySupplierPayment($this->recorder, $payment, [[
        'bill_id' => $approved->getKey(),
        'amount' => '210.00',
    ]]);

    expect($paid->status)->toBe('paid')
        ->and($approved->refresh()->status)->toBe('paid')
        ->and($paid->allocations()->count())->toBe(1)
        ->and(JournalEntry::query()->where('source_type', SupplierPayment::class)->count())->toBe(1);
});

it('reports a computed payable tie-out from approved supplier documents', function (): void {
    $this->actingAs($this->recorder);
    $bill = Bill::factory()->create([
        'supplier_id' => $this->supplier->getKey(),
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
        'bill_date' => '2026-08-10',
    ]);
    $bill->lines()->create([
        'chart_account_id' => $this->expenseAccount->getKey(),
        'description' => 'Utilities',
        'quantity' => '1.000',
        'unit_price' => '100.00',
        'tax_amount' => '0.00',
        'line_total' => '100.00',
        'sort_order' => 1,
    ]);

    $this->actingAs($this->approver);
    $this->documents->approveBill($this->approver, $bill);

    $summary = app(AccountsPayableService::class)->aging(CarbonImmutable::parse('2026-08-20'));

    expect($summary['outstanding_minor'])->toBe(10_000)
        ->and($summary['control_account_minor'])->toBe(10_000)
        ->and($summary['tie_out_difference_minor'])->toBe(0)
        ->and($summary['is_reconciled'])->toBeTrue();
});

it('shows ordered, received, cumulative billed, and variance values for a PO-linked bill line', function (): void {
    $purchaseOrder = PurchaseOrder::factory()->sent()->create([
        'destination_warehouse_id' => Warehouse::factory(),
        'supplier_id' => $this->supplier->getKey(),
    ]);
    $variant = ProductVariant::factory()->create();
    $unit = Unit::factory()->create();
    $purchaseOrderLine = $purchaseOrder->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => 10,
        'unit_cost' => '5.00',
        'line_total' => '50.00',
    ]);
    $receipt = InventoryOperation::factory()->done()->create([
        'operation_type' => OperationType::Receipt,
        'stage' => OperationStage::Done,
        'source_document_type' => PurchaseOrder::class,
        'source_document_id' => $purchaseOrder->getKey(),
        'destination_warehouse_id' => $purchaseOrder->destination_warehouse_id,
        'supplier_id' => $this->supplier->getKey(),
    ]);
    $receipt->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => 4,
    ]);

    $bill = Bill::factory()->create([
        'supplier_id' => $this->supplier->getKey(),
        'purchase_order_id' => $purchaseOrder->getKey(),
        'subtotal' => '25.00',
        'tax_total' => '0.00',
        'total_amount' => '25.00',
    ]);
    $line = $bill->lines()->create([
        'purchase_order_line_id' => $purchaseOrderLine->getKey(),
        'product_variant_id' => $variant->getKey(),
        'chart_account_id' => $this->expenseAccount->getKey(),
        'description' => 'PO-linked goods',
        'quantity' => '5.000',
        'unit_price' => '5.00',
        'tax_amount' => '0.00',
        'line_total' => '25.00',
    ]);

    expect($line->receivedQuantity())->toBe(4.0)
        ->and($line->cumulativeBilledQuantity())->toBe(5.0)
        ->and($line->hasQuantityVariance())->toBeTrue()
        ->and($line->hasUnitPriceVariance())->toBeFalse();
});

it('prevents the recorder from approving their own bill and records lifecycle audit entries', function (): void {
    $bill = $this->documents->recordBill($this->recorder, [
        'supplier_id' => $this->supplier->getKey(),
        'supplier_reference' => 'SUP-SELF-001',
        'bill_date' => '2026-08-10',
        'subtotal' => '10.00',
        'tax_total' => '0.00',
        'total_amount' => '10.00',
        'description' => 'Self approval guard',
    ]);

    expect(fn (): Bill => $this->documents->approveBill($this->recorder, $bill))
        ->toThrow(AuthorizationException::class);

    expect(AuditLog::query()
        ->where('subject_type', Bill::class)
        ->where('subject_id', $bill->getKey())
        ->where('description', 'accounting.bill.created')
        ->exists())->toBeTrue();
});
