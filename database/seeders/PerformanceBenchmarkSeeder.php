<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BillStatus;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\MovementType;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesSetting;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Populates a production-scale dataset for WP-4.1 (PHASE_4_PLAN.md §1) so report
 * and reconciliation queries can be benchmarked against realistic volume rather
 * than the small, hand-curated `*DemoSeeder` fixtures used elsewhere.
 *
 * Not wired into `DatabaseSeeder` — run explicitly and only against a disposable
 * database:
 *
 *   php artisan db:seed --class=Database\\Seeders\\PerformanceBenchmarkSeeder
 *
 * Uses bulk `insert()` in chunks instead of factory `create()` per row — at
 * these volumes (50k invoices, ~125k invoice lines, 500k movements) per-row
 * Eloquent inserts would take hours. This intentionally bypasses model events,
 * observers, and posting services, so the data is valid for query-shape and
 * volume benchmarking (WP-4.1) only — never for correctness/business-rule tests.
 *
 * Covers the report services that operate on sales documents, receivables,
 * the general ledger, tax, payables, and inventory movements
 * (`SalesReportService`, `AccountsReceivableService`, `InventoryReportService`,
 * `CustomerTimelineService`, `InventoryLotReconciliationService`,
 * `FinancialReportService`, `TaxRegisterService`, `AccountsPayableService`).
 * It intentionally does not populate purchasing-commitment, CRM, employee, or
 * support data (`PurchasingReportService`, `CrmFunnelReportService`,
 * `EmployeeReportService`, `SupportReportService`) — each of those needs its
 * own, unrelated FK tree (purchase orders and receipts; campaigns and leads;
 * sales plans and visits; tickets and maintenance schedules) and is unseeded
 * scope for a follow-up pass.
 *
 * The ledger seeded here is a simplified one entry per invoice (Dr
 * receivable, Cr revenue, Cr tax payable) and one per bill (Dr expense, Cr
 * payable) — enough for realistic volume and query shape, but it does not
 * reconcile control accounts to the cent (`AccountsReceivableService`'s and
 * `AccountsPayableService`'s tie-out will show a nonzero difference). That is
 * a deliberate simplification for a volume benchmark, not a correctness
 * fixture.
 */
final class PerformanceBenchmarkSeeder extends Seeder
{
    private const int Customers = 5_000;

    private const int Warehouses = 6;

    private const int ProductVariants = 400;

    private const int Invoices = 50_000;

    private const int MaxLinesPerInvoice = 3;

    private const int Movements = 500_000;

    private const int Suppliers = 200;

    private const int Bills = 10_000;

    private const int Expenses = 5_000;

    private const int SpanYears = 3;

    private const int ChunkSize = 2_000;

    public function run(): void
    {
        $this->command->warn('Seeding a large synthetic dataset for performance benchmarking. This bypasses domain services and is not valid fixture data for correctness tests.');

        $warehouseIds = $this->seedWarehouses();
        $variantIds = $this->seedProductVariants();
        $customerIds = $this->seedCustomers();
        $invoiceIds = $this->seedInvoicesAndLines($customerIds, $variantIds);
        $this->seedMovements($variantIds, $warehouseIds);

        $accountIds = $this->seedChartOfAccountsAndSettings();
        $this->seedLedgerAndTaxForInvoices($accountIds);
        $supplierIds = $this->seedSuppliers();
        $this->seedBills($accountIds, $supplierIds);
        $this->seedExpenses($accountIds, $supplierIds);

        $this->command->info(sprintf(
            'Seeded %d customers, %d product variants, %d invoices, %d movements, %d suppliers, %d bills, %d expenses.',
            count($customerIds),
            count($variantIds),
            count($invoiceIds),
            self::Movements,
            count($supplierIds),
            self::Bills,
            self::Expenses,
        ));
    }

    /** @return list<int> */
    private function seedWarehouses(): array
    {
        return $this->toIntList(Warehouse::factory()->count(self::Warehouses)->create()->pluck('id'));
    }

    /** @return list<int> */
    private function seedProductVariants(): array
    {
        $ids = [];
        $this->command->getOutput()->progressStart(self::ProductVariants);

        for ($i = 0; $i < self::ProductVariants; $i++) {
            $product = Product::factory()->create();
            $unit = Unit::factory()->create();
            $ids[] = ProductVariant::factory()->create([
                'product_id' => $product->id,
                'unit_id' => $unit->id,
            ])->id;
            $this->command->getOutput()->progressAdvance();
        }

        $this->command->getOutput()->progressFinish();

        return $ids;
    }

    /** @return list<int> */
    private function seedCustomers(): array
    {
        $ids = [];

        foreach (array_chunk(range(1, self::Customers), self::ChunkSize) as $batch) {
            $created = $this->toIntList(CustomerProfile::factory()->count(count($batch))->create()->pluck('id'));
            $ids = [...$ids, ...$created];
        }

        return $ids;
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return list<int>
     */
    private function toIntList(Collection $ids): array
    {
        $result = [];

        foreach ($ids as $id) {
            $result[] = is_numeric($id) ? (int) $id : 0;
        }

        return $result;
    }

    /**
     * @param  list<int>  $customerIds
     * @param  list<int>  $variantIds
     * @return list<int>
     */
    private function seedInvoicesAndLines(array $customerIds, array $variantIds): array
    {
        $now = CarbonImmutable::now();
        $spanDays = self::SpanYears * 365;
        $invoiceIds = [];

        $this->command->getOutput()->progressStart(self::Invoices);

        foreach (array_chunk(range(1, self::Invoices), self::ChunkSize) as $batch) {
            $invoiceRows = [];
            $lineRows = [];

            foreach ($batch as $sequence) {
                $invoiceDate = $now->subDays(random_int(0, $spanDays));
                $status = $this->randomInvoiceStatus();
                $subtotal = random_int(10_000, 1_000_000) / 100;
                $tax = round($subtotal * 0.05, 2);
                $total = $subtotal + $tax;
                $isIssued = $status !== InvoiceStatus::Draft;

                $invoiceRows[] = [
                    'invoice_number' => sprintf('BENCH-INV-%08d', $sequence),
                    'customer_id' => $customerIds[random_int(0, count($customerIds) - 1)],
                    'invoice_date' => $invoiceDate->toDateString(),
                    'due_date' => $invoiceDate->addDays(30)->toDateString(),
                    'description' => 'Performance benchmark invoice',
                    'subtotal' => $subtotal,
                    'tax_total' => $tax,
                    'total_amount' => $total,
                    'amount_paid' => $status === InvoiceStatus::WrittenOff ? $total : round($total * (random_int(0, 100) / 100), 2),
                    'credited_amount' => 0,
                    'recognised_tax_amount' => $tax,
                    'status' => $status->value,
                    'issued_at' => $isIssued ? $invoiceDate : null,
                    'sent_at' => $status === InvoiceStatus::Sent ? $invoiceDate->addDay() : null,
                    'created_at' => $invoiceDate,
                    'updated_at' => $invoiceDate,
                ];
            }

            $currentMaxId = DB::table('invoices')->max('id');
            $startId = (is_numeric($currentMaxId) ? (int) $currentMaxId : 0) + 1;
            DB::table('invoices')->insert($invoiceRows);
            $endId = $startId + count($invoiceRows) - 1;
            $invoiceIds = [...$invoiceIds, ...range($startId, $endId)];

            foreach (range($startId, $endId) as $invoiceId) {
                $lineCount = random_int(1, self::MaxLinesPerInvoice);
                for ($line = 0; $line < $lineCount; $line++) {
                    $quantity = random_int(1, 20);
                    $unitPrice = random_int(500, 20_000) / 100;
                    $lineRows[] = [
                        'invoice_id' => $invoiceId,
                        'product_variant_id' => $variantIds[random_int(0, count($variantIds) - 1)],
                        'order_line_id' => null,
                        'description' => 'Benchmark line item',
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'tax_amount' => round($quantity * $unitPrice * 0.05, 2),
                        'line_total' => round($quantity * $unitPrice * 1.05, 2),
                        'sort_order' => $line,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            DB::table('invoice_lines')->insert($lineRows);
            $this->command->getOutput()->progressAdvance(count($batch));
        }

        $this->command->getOutput()->progressFinish();

        return $invoiceIds;
    }

    private function randomInvoiceStatus(): InvoiceStatus
    {
        return match (true) {
            random_int(1, 100) <= 5 => InvoiceStatus::Draft,
            random_int(1, 100) <= 60 => InvoiceStatus::Sent,
            random_int(1, 100) <= 90 => InvoiceStatus::Issued,
            random_int(1, 100) <= 97 => InvoiceStatus::WrittenOff,
            default => InvoiceStatus::Cancelled,
        };
    }

    /**
     * @param  list<int>  $variantIds
     * @param  list<int>  $warehouseIds
     */
    private function seedMovements(array $variantIds, array $warehouseIds): void
    {
        $now = CarbonImmutable::now();
        $spanDays = self::SpanYears * 365;
        $movementTypes = MovementType::cases();

        $this->command->getOutput()->progressStart(self::Movements);

        foreach (array_chunk(range(1, self::Movements), self::ChunkSize) as $batch) {
            $rows = [];

            foreach ($batch as $ignored) {
                $type = $movementTypes[random_int(0, count($movementTypes) - 1)];
                $isOutbound = in_array($type, [MovementType::Sale, MovementType::Disposal, MovementType::ServiceConsumption], true);
                $quantity = random_int(1, 50000) / 1000;
                $movedAt = $now->subDays(random_int(0, $spanDays));

                $rows[] = [
                    'product_variant_id' => $variantIds[random_int(0, count($variantIds) - 1)],
                    'warehouse_id' => $warehouseIds[random_int(0, count($warehouseIds) - 1)],
                    'movement_type' => $type->value,
                    'quantity' => $isOutbound ? -$quantity : $quantity,
                    'source_type' => null,
                    'source_id' => null,
                    'notes' => null,
                    'status' => 'confirmed',
                    'created_at' => $movedAt,
                    'updated_at' => $movedAt,
                ];
            }

            DB::table('inventory_movements')->insert($rows);
            $this->command->getOutput()->progressAdvance(count($batch));
        }

        $this->command->getOutput()->progressFinish();
    }

    /**
     * Reuses the small, idempotent `ChartOfAccountsSeeder` (Eloquent, not bulk
     * insert — its volume is a handful of rows) and points `SalesSetting` at
     * the accounts the sales lifecycle posts to, matching the codes
     * `AccountsReceivableService`/`AccountsPayableService`/`TaxRegisterService`
     * look up.
     *
     * @return array<string, int> chart account id keyed by code
     */
    private function seedChartOfAccountsAndSettings(): array
    {
        $this->call(ChartOfAccountsSeeder::class);

        $accountIds = [];
        foreach (ChartAccount::query()->whereIn('code', ['1200', '2100', '2300', '2350', '2400', '4100', '5100'])->get(['id', 'code']) as $account) {
            $accountIds[(string) $account->code] = (int) $account->id;
        }

        SalesSetting::current()->forceFill([
            'receivable_account_id' => $this->accountId($accountIds, '1200'),
            'revenue_account_id' => $this->accountId($accountIds, '4100'),
            'deferred_tax_account_id' => $this->accountId($accountIds, '2350'),
            'tax_payable_account_id' => $this->accountId($accountIds, '2300'),
            'customer_deposits_account_id' => $this->accountId($accountIds, '2400'),
        ])->save();

        return $accountIds;
    }

    /** @param  array<string, int>  $accountIds */
    private function accountId(array $accountIds, string $code): int
    {
        if (! array_key_exists($code, $accountIds)) {
            throw new RuntimeException("ChartOfAccountsSeeder did not produce account code {$code}.");
        }

        return $accountIds[$code];
    }

    /**
     * One posted journal entry per already-seeded invoice (Dr receivable, Cr
     * revenue, Cr tax payable) plus a matching `TaxRecognitionEntry`, so
     * `FinancialReportService` and `TaxRegisterService` have realistic ledger
     * volume tied to the invoice volume above.
     *
     * @param  array<string, int>  $accountIds
     */
    private function seedLedgerAndTaxForInvoices(array $accountIds): void
    {
        $total = (int) DB::table('invoices')->count();
        $this->command->getOutput()->progressStart($total);

        DB::table('invoices')
            ->orderBy('id')
            ->select(['id', 'invoice_date', 'subtotal', 'tax_total', 'total_amount'])
            ->chunk(self::ChunkSize, function (Collection $invoices) use ($accountIds): void {
                $entryRows = [];
                $now = now();

                foreach ($invoices as $invoice) {
                    $invoiceId = is_numeric($invoice->id) ? (int) $invoice->id : 0;
                    $entryRows[] = [
                        'fiscal_period_id' => null,
                        'entry_number' => sprintf('BENCH-JE-INV-%08d', $invoiceId),
                        'entry_date' => $invoice->invoice_date,
                        'description' => 'Performance benchmark invoice posting',
                        'source_type' => Invoice::class,
                        'source_id' => $invoiceId,
                        'status' => JournalEntryStatus::Posted->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $currentMaxId = DB::table('journal_entries')->max('id');
                $startId = (is_numeric($currentMaxId) ? (int) $currentMaxId : 0) + 1;
                DB::table('journal_entries')->insert($entryRows);

                $lineRows = [];
                $taxRows = [];
                $entryId = $startId;

                foreach ($invoices as $invoice) {
                    $invoiceId = is_numeric($invoice->id) ? (int) $invoice->id : 0;

                    $lineRows[] = [
                        'journal_entry_id' => $entryId,
                        'chart_account_id' => $this->accountId($accountIds, '1200'),
                        'debit' => $invoice->total_amount,
                        'credit' => 0,
                        'description' => null,
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $lineRows[] = [
                        'journal_entry_id' => $entryId,
                        'chart_account_id' => $this->accountId($accountIds, '4100'),
                        'debit' => 0,
                        'credit' => $invoice->subtotal,
                        'description' => null,
                        'sort_order' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $lineRows[] = [
                        'journal_entry_id' => $entryId,
                        'chart_account_id' => $this->accountId($accountIds, '2300'),
                        'debit' => 0,
                        'credit' => $invoice->tax_total,
                        'description' => null,
                        'sort_order' => 2,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $taxRows[] = [
                        'tax_date' => $invoice->invoice_date,
                        'direction' => 'output',
                        'tax_type' => 'standard',
                        'tax_amount' => $invoice->tax_total,
                        'source_type' => Invoice::class,
                        'source_id' => $invoiceId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $entryId++;
                }

                DB::table('journal_entry_lines')->insert($lineRows);
                DB::table('tax_recognition_entries')->insert($taxRows);
                $this->command->getOutput()->progressAdvance($invoices->count());
            });

        $this->command->getOutput()->progressFinish();
    }

    /** @return list<int> */
    private function seedSuppliers(): array
    {
        return $this->toIntList(Supplier::factory()->count(self::Suppliers)->create()->pluck('id'));
    }

    /**
     * @param  array<string, int>  $accountIds
     * @param  list<int>  $supplierIds
     */
    private function seedBills(array $accountIds, array $supplierIds): void
    {
        $now = CarbonImmutable::now();
        $spanDays = self::SpanYears * 365;

        $this->command->getOutput()->progressStart(self::Bills);

        foreach (array_chunk(range(1, self::Bills), self::ChunkSize) as $batch) {
            $rows = [];

            foreach ($batch as $sequence) {
                $billDate = $now->subDays(random_int(0, $spanDays));
                $status = $this->randomBillStatus();
                $subtotal = random_int(10_000, 500_000) / 100;
                $tax = round($subtotal * 0.05, 2);
                $total = $subtotal + $tax;

                $rows[] = [
                    'bill_number' => sprintf('BENCH-BILL-%08d', $sequence),
                    'supplier_id' => $supplierIds[random_int(0, count($supplierIds) - 1)],
                    'supplier_reference' => sprintf('BENCH-REF-%08d', $sequence),
                    'expense_account_id' => $this->accountId($accountIds, '5100'),
                    'bill_date' => $billDate->toDateString(),
                    'due_date' => $billDate->addDays(30)->toDateString(),
                    'description' => 'Performance benchmark bill',
                    'subtotal' => $subtotal,
                    'tax_total' => $tax,
                    'total_amount' => $total,
                    'amount_paid' => $status === BillStatus::Paid ? $total : round($total * (random_int(0, 80) / 100), 2),
                    'status' => $status->value,
                    'approved_at' => $status !== BillStatus::Draft ? $billDate : null,
                    'paid_at' => $status === BillStatus::Paid ? $billDate->addDays(15) : null,
                    'created_at' => $billDate,
                    'updated_at' => $billDate,
                ];
            }

            DB::table('bills')->insert($rows);
            $this->command->getOutput()->progressAdvance(count($batch));
        }

        $this->command->getOutput()->progressFinish();
    }

    private function randomBillStatus(): BillStatus
    {
        return match (true) {
            random_int(1, 100) <= 10 => BillStatus::Draft,
            random_int(1, 100) <= 40 => BillStatus::Approved,
            random_int(1, 100) <= 60 => BillStatus::PartiallyPaid,
            random_int(1, 100) <= 95 => BillStatus::Paid,
            default => BillStatus::Cancelled,
        };
    }

    /**
     * @param  array<string, int>  $accountIds
     * @param  list<int>  $supplierIds
     */
    private function seedExpenses(array $accountIds, array $supplierIds): void
    {
        $now = CarbonImmutable::now();
        $spanDays = self::SpanYears * 365;

        $this->command->getOutput()->progressStart(self::Expenses);

        foreach (array_chunk(range(1, self::Expenses), self::ChunkSize) as $batch) {
            $rows = [];

            foreach ($batch as $sequence) {
                $expenseDate = $now->subDays(random_int(0, $spanDays));
                $status = $this->randomExpenseStatus();
                $subtotal = random_int(2_000, 100_000) / 100;
                $tax = round($subtotal * 0.05, 2);
                $total = $subtotal + $tax;

                $rows[] = [
                    'expense_number' => sprintf('BENCH-EXP-%08d', $sequence),
                    'supplier_id' => $supplierIds[random_int(0, count($supplierIds) - 1)],
                    'expense_account_id' => $this->accountId($accountIds, '5100'),
                    'expense_date' => $expenseDate->toDateString(),
                    'merchant_name' => null,
                    'description' => 'Performance benchmark expense',
                    'subtotal' => $subtotal,
                    'tax_total' => $tax,
                    'total_amount' => $total,
                    'amount_paid' => $status === ExpenseStatus::Paid ? $total : 0,
                    'status' => $status->value,
                    'approved_at' => $status !== ExpenseStatus::Draft ? $expenseDate : null,
                    'paid_at' => $status === ExpenseStatus::Paid ? $expenseDate->addDays(5) : null,
                    'created_at' => $expenseDate,
                    'updated_at' => $expenseDate,
                ];
            }

            DB::table('expenses')->insert($rows);
            $this->command->getOutput()->progressAdvance(count($batch));
        }

        $this->command->getOutput()->progressFinish();
    }

    private function randomExpenseStatus(): ExpenseStatus
    {
        return match (true) {
            random_int(1, 100) <= 10 => ExpenseStatus::Draft,
            random_int(1, 100) <= 40 => ExpenseStatus::Approved,
            random_int(1, 100) <= 95 => ExpenseStatus::Paid,
            default => ExpenseStatus::Cancelled,
        };
    }
}
