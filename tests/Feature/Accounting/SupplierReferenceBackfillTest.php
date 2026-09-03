<?php

declare(strict_types=1);

use App\Models\ChartAccount;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function runSupplierReferenceMigrationUp(): void
{
    $migration = require database_path(
        'migrations/2026_09_04_100500_enforce_supplier_reference_uniqueness.php',
    );

    if (! is_object($migration) || ! is_callable([$migration, 'up'])) {
        throw new LogicException('Supplier-reference migration must expose up().');
    }

    call_user_func([$migration, 'up']);
}

function runSupplierReferenceMigrationDown(): void
{
    $migration = require database_path(
        'migrations/2026_09_04_100500_enforce_supplier_reference_uniqueness.php',
    );

    if (! is_object($migration) || ! is_callable([$migration, 'down'])) {
        throw new LogicException('Supplier-reference migration must expose down().');
    }

    call_user_func([$migration, 'down']);
}

function insertLegacyBill(
    int $supplierId,
    int $expenseAccountId,
    string $billNumber,
    ?string $supplierReference,
): void {
    DB::table('bills')->insert([
        'bill_number' => $billNumber,
        'supplier_id' => $supplierId,
        'supplier_reference' => $supplierReference,
        'expense_account_id' => $expenseAccountId,
        'bill_date' => '2026-08-01',
        'description' => 'Legacy supplier invoice',
        'subtotal' => '10.00',
        'tax_total' => '0.00',
        'total_amount' => '10.00',
        'amount_paid' => '0.00',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('backfills legacy blank references with an explicitly flagged placeholder', function (): void {
    $supplier = Supplier::factory()->create();
    $account = ChartAccount::factory()->create();
    runSupplierReferenceMigrationDown();

    try {
        insertLegacyBill(
            (int) $supplier->getKey(),
            (int) $account->getKey(),
            'BILL-LEGACY-0001',
            null,
        );

        runSupplierReferenceMigrationUp();

        $bill = DB::table('bills')
            ->where('bill_number', 'BILL-LEGACY-0001')
            ->sole();

        expect($bill->supplier_reference)->toBe('LEGACY-BILL-LEGACY-0001')
            ->and($bill->supplier_reference_backfilled_at)->not->toBeNull()
            ->and(Schema::hasColumn('bills', 'supplier_reference_backfilled_at'))->toBeTrue();
    } finally {
        if (Schema::hasColumn('bills', 'supplier_reference_backfilled_at')) {
            DB::table('bills')->where('bill_number', 'BILL-LEGACY-0001')->delete();
        } else {
            runSupplierReferenceMigrationUp();
        }
    }
});

it('refuses to migrate dirty duplicate supplier references and names the bills', function (): void {
    $supplier = Supplier::factory()->create();
    $account = ChartAccount::factory()->create();
    runSupplierReferenceMigrationDown();

    insertLegacyBill(
        (int) $supplier->getKey(),
        (int) $account->getKey(),
        'BILL-DUP-0001',
        'SUP-DUP-77',
    );
    insertLegacyBill(
        (int) $supplier->getKey(),
        (int) $account->getKey(),
        'BILL-DUP-0002',
        'SUP-DUP-77',
    );

    try {
        runSupplierReferenceMigrationUp();

        test()->fail('Expected duplicate supplier references to block the migration.');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())
            ->toContain('BILL-DUP-0001')
            ->toContain('BILL-DUP-0002');
    } finally {
        DB::table('bills')
            ->whereIn('bill_number', ['BILL-DUP-0001', 'BILL-DUP-0002'])
            ->delete();

        if (! Schema::hasColumn('bills', 'supplier_reference_backfilled_at')) {
            runSupplierReferenceMigrationUp();
        }
    }
});
