<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Invoice;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\TaxRegisterService;
use App\Services\Inventory\InventoryLotReconciliationService;
use App\Services\Sales\InvoiceBalanceService;
use App\Services\Sales\SalesDashboardFilters;
use App\Services\Sales\SalesDashboardMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only QA report for the demo month: record counts, ledger / stock integrity, the
 * four dashboards recomputed from the database, and the status coverage matrix.
 *
 *     php artisan db:seed --class='Database\Seeders\Demo\DemoVerificationSeeder'
 */
final class DemoVerificationSeeder extends Seeder
{
    private const string From = '2026-09-04 00:00:00';

    private const string To = '2026-10-03 23:59:59';

    /**
     * Enum => [table, column, optional extra where]. Used for the status coverage matrix.
     *
     * @var array<string, array{0: string, 1: string, 2?: string}>
     */
    private const array Coverage = [
        'Sales.Quotation' => ['quotations', 'status'],
        'Sales.Order' => ['orders', 'status'],
        'Sales.Invoice' => ['invoices', 'status'],
        'Sales.CreditNote' => ['credit_notes', 'status'],
        'Sales.WriteOff' => ['receivable_write_offs', 'status'],
        'Sales.Shipment' => ['shipments', 'status'],
        'Payments.Payment' => ['payments', 'status'],
        'Payments.ProviderTransaction' => ['payment_transactions', 'status'],
        'Payments.Refund' => ['refunds', 'status'],
        'Accounting.Journal' => ['journal_entries', 'status'],
        'Accounting.Bill' => ['bills', 'status'],
        'Accounting.Expense' => ['expenses', 'status'],
        'Accounting.SupplierPayment' => ['supplier_payments', 'status'],
        'Purchasing.Order' => ['purchase_orders', 'status'],
        'Purchasing.Inbound' => ['purchase_inbounds', 'status'],
        'Purchasing.Rfq' => ['purchase_rfqs', 'status'],
        'Purchasing.Agreement' => ['purchase_agreements', 'status'],
        'Purchasing.SupplierConfirmation' => ['supplier_confirmations', 'confirmation_status'],
        'Inventory.Receipt' => ['inventory_operations', 'stage', "operation_type = 'receipt'"],
        'Inventory.Delivery' => ['inventory_operations', 'stage', "operation_type = 'delivery'"],
        'Inventory.Transfer' => ['inventory_operations', 'stage', "operation_type = 'internal_transfer'"],
        'Inventory.Adjustment' => ['inventory_adjustments', 'status'],
        'Inventory.Count' => ['inventory_counts', 'status'],
        'Inventory.Return' => ['inventory_returns', 'status'],
        'Inventory.Correction' => ['inventory_corrections', 'status'],
        'Inventory.ConditionChange' => ['inventory_condition_changes', 'status'],
        'Inventory.Reservation' => ['inventory_reservations', 'status'],
        'Inventory.Replenishment' => ['replenishment_requirements', 'status'],
        'Crm.Lead' => ['leads', 'status'],
        'Crm.Opportunity.Stage' => ['sales_opportunities', 'stage'],
        'Crm.Opportunity.Review' => ['sales_opportunities', 'status'],
        'Crm.Campaign' => ['campaigns', 'status'],
        'Crm.CampaignRecipient' => ['campaign_recipients', 'send_status'],
        'Crm.CustomerApproval' => ['customer_profiles', 'approval_status'],
        'Employees.Plan' => ['sales_plans', 'status'],
        'Employees.Task' => ['plan_tasks', 'status'],
        'Employees.Visit' => ['customer_visits', 'status'],
        'Employees.VoiceNote' => ['employee_voice_notes', 'status'],
        'Employees.Transcription' => ['voice_note_transcriptions', 'status'],
        'Employees.Salary' => ['employee_salary_calculations', 'status'],
        'Support.Ticket' => ['tickets', 'status'],
        'Support.Maintenance' => ['maintenance_records', 'status'],
        'Support.MaintenanceTask' => ['maintenance_tasks', 'status'],
        'Support.Occurrence' => ['maintenance_schedule_occurrences', 'status'],
        'Notifications.Delivery' => ['notification_deliveries', 'status'],
    ];

    public function run(): void
    {
        $this->section('Record counts');
        $this->counts();

        $this->section('Integrity');
        $this->integrity();

        $this->section('Inventory dashboard (2026-09-04 .. 2026-10-03)');
        $this->guard($this->inventoryDashboard(...));

        $this->section('Sales dashboard');
        $this->guard($this->salesDashboard(...));

        $this->section('Purchasing dashboard');
        $this->guard($this->purchasingDashboard(...));

        $this->section('Accounting dashboard');
        $this->guard($this->accountingDashboard(...));

        $this->section('Chart activity (distinct days)');
        $this->guard($this->chartActivity(...));

        $this->section('Status coverage matrix');
        $this->coverage();
    }

    private function counts(): void
    {
        $tables = [
            'users', 'customer_profiles', 'suppliers', 'employee_profiles', 'products', 'product_variants', 'warehouses',
            'inventory_movements', 'inventory_operations', 'inventory_adjustments', 'inventory_returns',
            'quotations', 'orders', 'invoices', 'credit_notes', 'refunds', 'payments', 'payment_transactions', 'payment_allocations',
            'purchase_orders', 'purchase_rfqs', 'purchase_agreements', 'supplier_confirmations', 'bills', 'supplier_payments',
            'expenses', 'journal_entries', 'tax_recognition_entries', 'leads', 'sales_opportunities', 'interactions', 'campaigns',
            'sales_plans', 'plan_tasks', 'customer_visits', 'employee_voice_notes', 'tickets', 'maintenance_records', 'maintenance_tasks',
            'notification_deliveries', 'activity_log',
        ];

        $rows = [];
        foreach ($tables as $table) {
            $rows[] = [$table, Schema::hasTable($table) ? DB::table($table)->count() : 'n/a'];
        }

        $this->command->table(['Table', 'Rows'], $rows);
    }

    private function integrity(): void
    {
        $unbalanced = DB::table('journal_entries as j')
            ->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'j.id')
            ->where('j.status', 'posted')
            ->groupBy('j.id')
            ->havingRaw('ROUND(SUM(l.debit) - SUM(l.credit), 2) <> 0')
            ->select('j.id')
            ->get()
            ->count();
        $this->line('Unbalanced posted journals', $unbalanced, $unbalanced === 0);

        $this->guard(function (): void {
            $trial = app(FinancialReportService::class)->trialBalance(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));
            $this->line('Trial balance foots', ($trial['foots'] ?? false) ? 'yes' : 'NO', (bool) ($trial['foots'] ?? false));
        });

        $this->guard(function (): void {
            $stock = app(InventoryLotReconciliationService::class)->inspectDetailed();
            $errors = count($stock['errors'] ?? []);
            $this->line('Stock ledger reconciliation errors', $errors, $errors === 0);
        });

        $negative = DB::table('inventory_stocks')->where('on_hand_quantity', '<', 0)->orWhere('reserved_quantity', '<', 0)->count();
        $this->line('Negative stock rows', $negative, $negative === 0);

        $overReserved = DB::table('inventory_stocks')->whereColumn('reserved_quantity', '>', 'on_hand_quantity')->count();
        $this->line('Reserved > on hand rows', $overReserved, $overReserved === 0);

        $this->guard(function (): void {
            $tax = app(TaxRegisterService::class);
            foreach ([['2026-09-01', '2026-09-30'], ['2026-10-01', '2026-10-31']] as [$from, $to]) {
                $reconciliation = $tax->reconciliation(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
                $differences = collect(['deferred', 'payable', 'input'])->map(fn (string $k): string => (string) ($reconciliation[$k]['difference'] ?? '?'))->implode(' / ');
                $this->line("Tax register differences {$from}", $differences, collect(['deferred', 'payable', 'input'])->every(fn (string $k): bool => (float) ($reconciliation[$k]['difference'] ?? 1) === 0.0));
            }
        });
    }

    private function inventoryDashboard(): void
    {
        $value = (float) DB::table('inventory_stocks')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.product_variant_id')
            ->selectRaw('COALESCE(SUM(inventory_stocks.available_quantity * COALESCE(product_variants.cost_price, 0)), 0) as t')
            ->value('t');
        $this->line('Stock value (AED, target 70k-140k)', number_format($value, 2), $value >= 70000 && $value <= 140000);

        foreach (DB::table('inventory_stocks')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_stocks.warehouse_id')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.product_variant_id')
            ->where('warehouses.code', 'like', 'WH-%')
            ->groupBy('warehouses.name')
            ->selectRaw('warehouses.name as n, SUM(inventory_stocks.available_quantity * product_variants.cost_price) as v')
            ->orderByDesc('v')
            ->get() as $row) {
            $this->line("  warehouse {$row->n}", number_format((float) $row->v, 2), true);
        }

        $needs = DB::table('inventory_stocks as s')
            ->where(function ($q): void {
                $q->where('s.available_quantity', '<=', 0)->orWhereExists(function ($e): void {
                    $e->select(DB::raw(1))->from('warehouse_replenishment_policies as p')
                        ->whereColumn('p.warehouse_id', 's.warehouse_id')->whereColumn('p.product_variant_id', 's.product_variant_id')
                        ->where('p.is_active', true)->whereColumn('s.available_quantity', '<=', 'p.min_quantity');
                });
            });
        $needsCount = (clone $needs)->count();
        $oos = (clone $needs)->where('s.available_quantity', '<=', 0)->count();
        $this->line('Needs reorder (target 4-7)', $needsCount, $needsCount >= 4 && $needsCount <= 7);
        $this->line('Out of stock (target >=2-3)', $oos, $oos >= 2);

        $requirements = DB::table('replenishment_requirements')->whereIn('status', ['open', 'partially_covered', 'covered'])->count();
        $this->line('Open replenishment requirements (>0)', $requirements, $requirements > 0);

        $awaiting = DB::table('inventory_operations')->whereNotIn('stage', ['done', 'canceled'])->count()
            + DB::table('inventory_adjustments')->where('status', 'draft')->count();
        $this->line('Awaiting action (target 4-10)', $awaiting, $awaiting >= 4 && $awaiting <= 10);
    }

    private function salesDashboard(): void
    {
        $filters = SalesDashboardFilters::fromPageFilters([]);
        $service = app(SalesDashboardMetricsService::class);
        $kpis = $service->kpis($filters);

        $direct = (float) DB::table('orders')->whereIn('status', ['confirmed', 'released', 'closed'])->whereBetween('confirmed_at', [self::From, self::To])->sum('grand_total');
        $directCount = DB::table('orders')->whereIn('status', ['confirmed', 'released', 'closed'])->whereBetween('confirmed_at', [self::From, self::To])->count();

        $this->line('Confirmed order value (service)', number_format((float) $kpis['value'], 2), $kpis['value'] >= 35000 && $kpis['value'] <= 90000);
        $this->line('Confirmed order value (direct SQL)', number_format($direct, 2), abs($direct - (float) $kpis['value']) < 0.01);
        $this->line('Confirmed orders (target 6-12)', $kpis['count'].' / direct '.$directCount, $kpis['count'] >= 6 && $kpis['count'] <= 12 && $kpis['count'] === $directCount);
        $this->line('Average order value', number_format((float) ($kpis['average_order_value'] ?? 0), 2), ($kpis['average_order_value'] ?? 0) > 0);
        $this->line('Quote->order conversion % (target 35-60)', (string) ($kpis['conversion_percent'] ?? 'n/a').' ('.$kpis['conversion_numerator'].'/'.$kpis['conversion_denominator'].')', ($kpis['conversion_percent'] ?? 0) >= 35 && ($kpis['conversion_percent'] ?? 0) <= 60);

        $this->line('Top products listed (>=5)', count($service->topProducts($filters)), count($service->topProducts($filters)) >= 5);
        $this->line('Top customers listed (>=5)', count($service->topCustomers($filters)), count($service->topCustomers($filters)) >= 5);
        $trendDays = DB::table('orders')->whereIn('status', ['confirmed', 'released', 'closed'])->whereBetween('confirmed_at', [self::From, self::To])->selectRaw('COUNT(DISTINCT DATE(confirmed_at)) d')->value('d');
        $this->line('Order confirmation days (>=8)', $trendDays, $trendDays >= 8);
    }

    private function purchasingDashboard(): void
    {
        $spend = (float) DB::table('purchase_orders')->where('currency_code', 'AED')->whereBetween('ordered_at', ['2026-09-04', '2026-10-03'])->sum('total_amount');
        $this->line('PO spend AED (target 45k-100k)', number_format($spend, 2), $spend >= 45000 && $spend <= 100000);

        $sourcing = DB::table('replenishment_requirements')->whereIn('status', ['open', 'partially_covered', 'covered'])->whereRaw('required_base_quantity - covered_base_quantity > 0')->count()
            + DB::table('sales_procurement_requirements')->whereNotIn('status', ['fulfilled', 'cancelled', 'superseded'])->whereNull('purchase_order_id')->count();
        $this->line('Needs sourcing (target 2-4)', $sourcing, $sourcing >= 2 && $sourcing <= 4);

        $approval = DB::table('purchase_orders')->where('status', 'pending_approval')->count();
        $this->line('Awaiting approval (target 1-3)', $approval, $approval >= 1 && $approval <= 3);

        $overdue = DB::table('purchase_orders')->whereDate('expected_at', '<', '2026-10-03')->whereNotIn('status', ['received', 'closed', 'cancelled'])->count();
        $this->line('Overdue deliveries (target 1-3)', $overdue, $overdue >= 1 && $overdue <= 3);

        $pendingConfirmation = fn ($q) => $q->select(DB::raw(1))->from('supplier_confirmations as c')->whereColumn('c.purchase_order_id', 'purchase_orders.id')->where('c.confirmation_status', 'pending');
        $stages = [
            'Approval' => DB::table('purchase_orders')->where('status', 'pending_approval')->count(),
            'Ready to send' => DB::table('purchase_orders')->where('status', 'accepted')->whereNull('sent_at')->count(),
            'Supplier' => DB::table('purchase_orders')->whereNotNull('sent_at')->whereExists($pendingConfirmation)->count(),
            'Receiving' => DB::table('purchase_orders')->whereNotNull('sent_at')->whereIn('status', ['accepted', 'partially_received'])->whereNotExists($pendingConfirmation)->count(),
            'Accounting' => DB::table('purchase_orders')->where('status', 'received')->where(function ($q): void {
                $q->whereNotExists(fn ($e) => $e->select(DB::raw(1))->from('bills')->whereColumn('bills.purchase_order_id', 'purchase_orders.id'))
                    ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('bills')->whereColumn('bills.purchase_order_id', 'purchase_orders.id')->whereNotIn('bills.status', ['paid', 'cancelled']));
            })->count(),
        ];
        foreach ($stages as $stage => $count) {
            $this->line("  open POs - {$stage} (>=1)", $count, $count >= 1);
        }

        $spendDays = DB::table('purchase_orders')->where('currency_code', 'AED')->whereBetween('ordered_at', ['2026-09-04', '2026-10-03'])->selectRaw('COUNT(DISTINCT ordered_at) d')->value('d');
        $this->line('PO spend days (>=8)', $spendDays, $spendDays >= 8);

        $upcoming = DB::table('purchase_orders')->whereNotNull('sent_at')->whereNotNull('expected_at')->whereIn('status', ['accepted', 'partially_received'])->count();
        $this->line('Upcoming deliveries (target 3-6)', $upcoming, $upcoming >= 3 && $upcoming <= 6);

        $suppliers = DB::table('purchase_orders')->distinct()->count('supplier_id');
        $this->line('Distinct suppliers on POs (>=5)', $suppliers, $suppliers >= 5);
    }

    private function accountingDashboard(): void
    {
        $balance = app(InvoiceBalanceService::class);
        $receivable = 0;
        $customers = [];
        $overdueCustomers = [];
        $partial = 0;
        foreach (Invoice::query()->whereNotNull('issued_at')->get() as $invoice) {
            $minor = $invoice->outstandingMinor();
            $receivable += $minor;
            if ($minor > 0) {
                $customers[$invoice->customer_id] = ($customers[$invoice->customer_id] ?? 0) + $minor;
                if ($invoice->isOverdue()) {
                    $overdueCustomers[$invoice->customer_id] = true;
                }
                if ((float) $invoice->amount_paid > 0) {
                    $partial++;
                }
            }
        }
        unset($balance);

        $this->line('Receivables outstanding AED (target 18k-40k)', number_format($receivable / 100, 2), $receivable >= 1800000 && $receivable <= 4000000);
        $this->line('Customers with open balance (>=5)', count($customers), count($customers) >= 5);
        $this->line('Overdue customers (>=2)', count($overdueCustomers), count($overdueCustomers) >= 2);
        $this->line('Partially paid open invoices (>=3)', $partial, $partial >= 3);

        $payables = (float) DB::table('bills')->whereIn('status', ['approved', 'partially_paid'])->selectRaw('COALESCE(SUM(total_amount - amount_paid), 0) as t')->value('t');
        $this->line('Payables outstanding AED (target 20k-45k)', number_format($payables, 2), $payables >= 20000 && $payables <= 45000);
        $owing = DB::table('bills')->whereIn('status', ['approved', 'partially_paid'])->whereRaw('total_amount - amount_paid > 0')->distinct()->count(DB::raw('COALESCE(supplier_id, resolved_supplier_id)'));
        $this->line('Suppliers with open bills (>=4)', $owing, $owing >= 4);
        $overdueBills = DB::table('bills')->whereIn('status', ['approved', 'partially_paid'])->whereDate('due_date', '<', '2026-10-03')->count();
        $this->line('Overdue bills (>=2)', $overdueBills, $overdueBills >= 2);

        $tax = app(TaxRegisterService::class)->period(CarbonImmutable::parse('2026-09-04'), CarbonImmutable::parse('2026-10-03'));
        $this->line('Net tax position (non-zero)', json_encode(array_map(fn ($v) => $v, $tax)), (float) ($tax['net_position'] ?? 0) !== 0.0);

        $awaiting = DB::table('journal_entries')->where('status', 'draft')->count() + DB::table('bills')->where('status', 'draft')->count();
        $this->line('Awaiting action: draft JEs + draft bills (>=5)', $awaiting, $awaiting >= 5);

        $days = DB::table('journal_entries')->where('status', 'posted')->whereBetween('entry_date', ['2026-09-04', '2026-10-03'])->selectRaw('COUNT(DISTINCT entry_date) d')->value('d');
        $this->line('Posted-journal days (>=10)', $days, $days >= 10);
        $journals = DB::table('journal_entries')->whereIn('status', ['posted', 'draft'])->count();
        $this->line('Journal entries (target 30-45)', $journals, $journals >= 30 && $journals <= 45);

        $period = DB::table('fiscal_periods')->where('is_closed', false)->orderBy('starts_at')->first();
        $checks = $period === null ? collect() : DB::table('fiscal_period_close_checks')->where('fiscal_period_id', $period->id)->orderByDesc('id')->get()->unique('check_key');
        $this->line('Period readiness ('.($period->name ?? 'n/a').')', $checks->where('passed', 1)->count().' passed / '.$checks->where('passed', 0)->count().' need attention', $checks->where('passed', 1)->count() >= 3 && $checks->where('passed', 0)->count() >= 1);
    }

    private function chartActivity(): void
    {
        $series = [
            'inventory movements' => DB::table('inventory_movements')->whereBetween('created_at', [self::From, self::To])->selectRaw('COUNT(DISTINCT DATE(created_at)) d')->value('d'),
            'quotations (issue_date)' => DB::table('quotations')->whereBetween('issue_date', ['2026-09-04', '2026-10-03'])->selectRaw('COUNT(DISTINCT issue_date) d')->value('d'),
            'invoices issued' => DB::table('invoices')->whereBetween('issued_at', [self::From, self::To])->selectRaw('COUNT(DISTINCT DATE(issued_at)) d')->value('d'),
            'payments (payment_date)' => DB::table('payments')->whereBetween('payment_date', ['2026-09-04', '2026-10-03'])->selectRaw('COUNT(DISTINCT payment_date) d')->value('d'),
            'tax recognition (tax_date)' => DB::table('tax_recognition_entries')->whereBetween('tax_date', ['2026-09-04', '2026-10-03'])->selectRaw('COUNT(DISTINCT tax_date) d')->value('d'),
        ];

        foreach ($series as $name => $days) {
            $this->line($name, $days, $days >= 8);
        }

        $movementTypes = DB::table('inventory_movements')->whereBetween('created_at', [self::From, self::To])->distinct()->pluck('movement_type')->implode(', ');
        $this->line('Movement types present', $movementTypes, true);
        $count = DB::table('inventory_movements')->count();
        $this->line('Inventory movements (target 80-120)', $count, $count >= 80 && $count <= 120);
    }

    private function coverage(): void
    {
        $rows = [];

        foreach (self::Coverage as $label => $definition) {
            [$table, $column] = $definition;
            $where = $definition[2] ?? null;

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                $rows[] = [$label, '(mapping)', 0, 'CHECK MAPPING'];

                continue;
            }

            $query = DB::table($table);
            if ($where !== null) {
                $query->whereRaw($where);
            }

            foreach ($query->selectRaw("{$column} as s, COUNT(*) as c")->groupBy($column)->orderBy($column)->get() as $row) {
                $rows[] = [$label, (string) $row->s, (int) $row->c, 'PASS'];
            }
        }

        $this->command->table(['Resource', 'Status', 'Count', 'Result'], $rows);
        $this->command->line('Enum cases with zero rows must be explained in the report (SKIPPED + reason).');
    }

    private function section(string $title): void
    {
        $this->command->newLine();
        $this->command->getOutput()->writeln("<options=bold>== {$title} ==</>");
    }

    private function line(string $label, mixed $value, bool $ok): void
    {
        $mark = $ok ? '<info>PASS</info>' : '<error>FAIL</error>';
        $this->command->getOutput()->writeln(sprintf('  %-58s %s  %s', $label, $mark, $value));
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->command->getOutput()->writeln('  <error>ERROR</error> '.$exception->getMessage());
        }
    }
}
