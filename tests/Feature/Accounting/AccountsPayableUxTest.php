<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\BillStatus;
use App\Enums\DashboardRole;
use App\Enums\ExpenseStatus;
use App\Enums\SupplierPaymentStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Bill;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;
use App\Services\Accounting\AccountsPayableService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();
});

it('lets payable viewers drill into bills and expenses without granting mutation rights', function (): void {
    $viewer = User::factory()->create();
    Role::findOrCreate(DashboardRole::SalesManager->value, 'web');
    $viewer->assignRole(DashboardRole::SalesManager->value);
    $viewer->givePermissionTo(AccountingPermission::PayableView->value);

    $bill = Bill::factory()->create();
    $expense = Expense::factory()->create();

    expect(Gate::forUser($viewer)->allows('viewAny', Bill::class))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('view', $bill))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('update', $bill))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('viewAny', Expense::class))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('view', $expense))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('update', $expense))->toBeFalse();

    $expenseId = $expense->getKey();
    assert(is_int($expenseId));

    expect(ExpenseResource::getUrl('view', ['record' => $expense]))
        ->toContain('/expenses/'.$expenseId);
});

it('keeps supplier statements aligned to allocated payments instead of full unallocated cash', function (): void {
    $supplier = Supplier::factory()->create();
    $bill = Bill::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => BillStatus::Approved,
        'bill_date' => '2026-08-01',
        'due_date' => '2026-08-10',
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
    ]);
    $payment = SupplierPayment::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => SupplierPaymentStatus::Paid,
        'amount' => '100.00',
        'payment_date' => '2026-08-15',
    ]);
    SupplierPaymentAllocation::factory()->create([
        'supplier_payment_id' => $payment->id,
        'bill_id' => $bill->id,
        'amount' => '40.00',
    ]);

    $statement = app(AccountsPayableService::class)->statement(
        $supplier,
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    $paymentEntry = collect($statement['entries'])->firstWhere('type', 'supplier_payment');
    assert(is_array($paymentEntry));

    expect($paymentEntry['payment_minor'])->toBe(4_000)
        ->and($statement['brought_forward_minor'])->toBe(0)
        ->and($statement['carried_forward_minor'])->toBe(6_000);
});

it('returns source record ids for payable document drill-down', function (): void {
    $supplier = Supplier::factory()->create();
    $bill = Bill::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => BillStatus::Approved,
        'bill_date' => '2026-08-01',
        'due_date' => '2026-08-20',
        'subtotal' => '75.00',
        'tax_total' => '0.00',
        'total_amount' => '75.00',
    ]);
    $expense = Expense::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => ExpenseStatus::Approved,
        'expense_date' => '2026-08-02',
        'due_date' => '2026-08-21',
        'subtotal' => '25.00',
        'tax_total' => '0.00',
        'total_amount' => '25.00',
    ]);

    $detail = app(AccountsPayableService::class)->supplierDetail(
        $supplier,
        CarbonImmutable::parse('2026-08-31'),
    );
    $documentRows = $detail['documents'] ?? null;
    assert(is_array($documentRows));

    $billId = null;
    $expenseId = null;
    foreach ($documentRows as $document) {
        if (! is_array($document)) {
            continue;
        }

        $type = $document['type'] ?? null;
        $documentId = $document['document_id'] ?? null;
        if (! is_int($documentId)) {
            continue;
        }

        if ($type === 'bill') {
            $billId = $documentId;
        } elseif ($type === 'expense') {
            $expenseId = $documentId;
        }
    }

    expect($billId)->toBe($bill->id)
        ->and($expenseId)->toBe($expense->id);
});
