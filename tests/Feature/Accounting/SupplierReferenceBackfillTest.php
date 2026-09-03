<?php

declare(strict_types=1);

use App\Models\ChartAccount;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** @return object{up:callable,down:callable} */
function supplierReferenceMigration(): object
{
    /** @var object{up:callable,down:callable} $migration */
    $migration = require database_path(
        'migrations/2026_09_04_100500_enforce_supplier_reference_uniqueness.php',
    );

    return $migration;
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
    $migration = supplierReferenceMigration();

    $migration->down();

    try {
        insertLegacyBill(
            (int) $supplier->getKey(),
            (int) $account->getKey(),
            'BILL-LEGACY-0001',
            null,
        );

        $migration->up();

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
            $migration->up();
        }
    }
});

it('refuses to migrate dirty duplicate supplier references and names the bills', function (): void {
    $supplier = Supplier::factory()->create();
    $account = ChartAccount::factory()->create();
    $migration = supplierReferenceMigration();

    $migration->down();

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
        $migration->up();

        test()->fail('Expected duplicate supplier references to block the migration.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('BILL-DUP-0001')
            ->toContain('BILL-DUP-0002');
    } finally {
        DB::table('bills')
            ->whereIn('bill_number', ['BILL-DUP-0001', 'BILL-DUP-0002'])
            ->delete();

        if (! Schema::hasColumn('bills', 'supplier_reference_backfilled_at')) {
            $migration->up();
        }
    }
});
