<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Exceptions\Domain\DuplicateSupplierReference;
use App\Exceptions\Domain\SupplierReferenceRequired;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\ChartAccount;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use App\Services\Purchasing\PurchasingReportService;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $this->actor = User::factory()->create();
    $this->actor->assignRole(DashboardRole::Accountant->value);
    $this->supplier = Supplier::factory()->create();
    $this->expenseAccount = ChartAccount::factory()->create();
    $this->documents = app(AccountingDocumentService::class);
});

/** @return array<string, mixed> */
function supplierBillAttributes(
    Supplier $supplier,
    ChartAccount $expenseAccount,
    ?string $reference,
): array {
    return [
        'supplier_id' => $supplier->getKey(),
        'supplier_reference' => $reference,
        'expense_account_id' => $expenseAccount->getKey(),
        'bill_date' => '2026-09-03',
        'description' => 'Supplier invoice duplicate control',
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
    ];
}

it('rejects the same supplier invoice reference twice and records the refusal', function (): void {
    $attributes = supplierBillAttributes(
        $this->supplier,
        $this->expenseAccount,
        'SUP-INV-2026-001',
    );

    $first = $this->documents->recordBill($this->actor, $attributes);

    expect(fn () => $this->documents->recordBill($this->actor, $attributes))
        ->toThrow(DuplicateSupplierReference::class, 'SUP-INV-2026-001');

    expect(Bill::query()->where('supplier_id', $this->supplier->getKey())->count())->toBe(1)
        ->and($first->supplier_reference)->toBe('SUP-INV-2026-001');

    $audit = AuditLog::query()
        ->where('description', 'accounting.bill.supplier_reference_rejected')
        ->latest('id')
        ->sole();

    expect($audit->getProperty('rejection_type'))->toBe('duplicate')
        ->and($audit->getProperty('supplier_reference'))->toBe('SUP-INV-2026-001');

    $report = app(PurchasingReportService::class)->duplicateReferenceAttempts();

    expect($report)->toHaveCount(1)
        ->and($report[0]['supplier'])->toBe($this->supplier->name)
        ->and($report[0]['supplier_reference'])->toBe('SUP-INV-2026-001');
});

it('allows the same supplier reference for different suppliers', function (): void {
    $otherSupplier = Supplier::factory()->create();

    $first = $this->documents->recordBill(
        $this->actor,
        supplierBillAttributes($this->supplier, $this->expenseAccount, 'INV-77'),
    );
    $second = $this->documents->recordBill(
        $this->actor,
        supplierBillAttributes($otherSupplier, $this->expenseAccount, 'INV-77'),
    );

    expect($first->supplier_reference)->toBe('INV-77')
        ->and($second->supplier_reference)->toBe('INV-77')
        ->and(Bill::query()->where('supplier_reference', 'INV-77')->count())->toBe(2);
});

it('requires a non-blank supplier invoice reference', function (?string $reference): void {
    expect(fn () => $this->documents->recordBill(
        $this->actor,
        supplierBillAttributes($this->supplier, $this->expenseAccount, $reference),
    ))->toThrow(SupplierReferenceRequired::class);

    $audit = AuditLog::query()
        ->where('description', 'accounting.bill.supplier_reference_rejected')
        ->latest('id')
        ->sole();

    expect(Bill::query()->count())->toBe(0)
        ->and($audit->getProperty('rejection_type'))->toBe('required');
})->with([
    'null' => null,
    'empty' => '',
    'spaces' => '   ',
]);

it('normalizes surrounding whitespace before persisting the reference', function (): void {
    $bill = $this->documents->recordBill(
        $this->actor,
        supplierBillAttributes($this->supplier, $this->expenseAccount, '  EXT-100  '),
    );

    expect($bill->supplier_reference)->toBe('EXT-100');
});

it('does not allow a reference to be reused after the original bill is soft deleted', function (): void {
    $bill = Bill::factory()->create([
        'supplier_id' => $this->supplier->getKey(),
        'supplier_reference' => 'PERMANENT-REF',
    ]);
    $bill->delete();

    expect(fn () => $this->documents->recordBill(
        $this->actor,
        supplierBillAttributes($this->supplier, $this->expenseAccount, 'PERMANENT-REF'),
    ))->toThrow(DuplicateSupplierReference::class);
});

it('enforces the supplier reference unique key when Eloquent is bypassed', function (): void {
    $bill = Bill::factory()->create([
        'supplier_id' => $this->supplier->getKey(),
        'supplier_reference' => 'DB-GUARD-001',
    ]);

    expect(fn () => DB::table('bills')->insert([
        'bill_number' => 'BILL-DIRECT-0001',
        'supplier_id' => $this->supplier->getKey(),
        'supplier_reference' => 'DB-GUARD-001',
        'expense_account_id' => $bill->expense_account_id,
        'bill_date' => '2026-09-03',
        'description' => 'Direct insert duplicate',
        'subtotal' => '10.00',
        'tax_total' => '0.00',
        'total_amount' => '10.00',
        'amount_paid' => '0.00',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(Bill::withTrashed()
        ->where('supplier_id', $this->supplier->getKey())
        ->where('supplier_reference', 'DB-GUARD-001')
        ->count())->toBe(1);
});
