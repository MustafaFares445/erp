<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Exceptions\Domain\DuplicateSupplierReference;
use App\Models\Bill;
use App\Models\ChartAccount;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('translates a database race on supplier reference into the domain exception', function (): void {
    (new AccountingPermissionSeeder)->run();

    $actor = User::factory()->create();
    $actor->assignRole(DashboardRole::Accountant->value);

    $supplier = Supplier::factory()->create();
    $expenseAccount = ChartAccount::factory()->create();

    $injected = false;

    Bill::creating(function (Bill $bill) use (&$injected, $expenseAccount): void {
        if ($injected || $bill->supplier_reference !== 'RACE-INV-001') {
            return;
        }

        $injected = true;

        // Simulates a competing writer that commits after the service's
        // friendly duplicate pre-check but before this bill insert reaches the
        // database unique key. Raw SQL deliberately bypasses the Bill model.
        DB::table('bills')->insert([
            'bill_number' => 'BILL-RACE-COMPETITOR',
            'supplier_id' => $bill->supplier_id,
            'supplier_reference' => 'RACE-INV-001',
            'expense_account_id' => $expenseAccount->getKey(),
            'bill_date' => '2026-09-03',
            'description' => 'Competing supplier invoice insert',
            'subtotal' => '25.00',
            'tax_total' => '0.00',
            'total_amount' => '25.00',
            'amount_paid' => '0.00',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn () => app(AccountingDocumentService::class)->recordBill($actor, [
        'supplier_id' => $supplier->getKey(),
        'supplier_reference' => 'RACE-INV-001',
        'expense_account_id' => $expenseAccount->getKey(),
        'bill_date' => '2026-09-03',
        'description' => 'Race-tested supplier invoice',
        'subtotal' => '25.00',
        'tax_total' => '0.00',
        'total_amount' => '25.00',
        'amount_paid' => '0.00',
    ]))->toThrow(DuplicateSupplierReference::class, 'RACE-INV-001');

    // Both writes were inside the failed transaction. The competing row was
    // only a deterministic way to force the unique-key race path.
    expect(Bill::withTrashed()
        ->where('supplier_id', $supplier->getKey())
        ->where('supplier_reference', 'RACE-INV-001')
        ->count())->toBe(0);
});
