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
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
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
        'Support.ServiceAppointment' => ['service_appointments', 'status'],
        'Support.Entitlement' => ['support_entitlements', 'status'],
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
            'support_service_levels', 'support_entitlements', 'service_appointments', 'ticket_satisfaction_responses',
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
            ->count();
        $this->line('Unbalanced posted journals', $unbalanced, $unbalanced === 0);

        $this->guard(function (): void {
            $trial = app(FinancialReportService::class)->trialBalance(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));
            $this->line('Trial balance foots', $trial['foots'] ? 'yes' : 'NO', $trial['foots']);
        });

        $this->guard(function (): void {
            $stock = app(InventoryLotReconciliationService::class)->inspectDetailed();
            $errors = count($stock['report']['errors']);
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

        if (Schema::hasTable('service_appointments')) {
            $inactiveTechnicians = DB::table('service_appointments as a')
                ->join('employee_profiles as e', 'e.id', '=', 'a.employee_id')
                ->where('e.is_active', false)
                ->whereNull('a.deleted_at')
                ->count();

            $this->line('Field visits assigned to inactive technicians', $inactiveTechnicians, $inactiveTechnicians === 0);
            $this->line('Field visits seeded (>=3)', DB::table('service_appointments')->whereNull('deleted_at')->count(), DB::table('service_appointments')->whereNull('deleted_at')->count() >= 3);
        }

        if (Schema::hasTable('support_entitlements')) {
            $invalidEntitlements = DB::table('support_entitlements as e')
                ->join('serialized_inventory_units as u', 'u.id', '=', 'e.serialized_inventory_unit_id')
                ->whereNotNull('e.serialized_inventory_unit_id')
                ->where(function (Builder $query): void {
                    $query->where('u.custody_type', '!=', 'customer')
                        ->orWhere('u.custody_reference_type', '!=', 'customer')
                        ->orWhereColumn('u.custody_reference_id', '!=', 'e.customer_id');
                })
                ->count();

            $this->line('Equipment entitlements with mismatched customer custody', $invalidEntitlements, $invalidEntitlements === 0);
        }

        if (Schema::hasTable('equipment_installations')) {
            $this->installations();
            $this->calibrations();
            $this->continuity();
        }

        if (Schema::hasTable('ticket_satisfaction_responses')) {
            $feedbackOnOpenTickets = DB::table('ticket_satisfaction_responses as s')
                ->join('tickets as t', 't.id', '=', 's.ticket_id')
                ->where('t.status', '!=', 'closed')
                ->count();

            $this->line('CSAT responses attached to non-closed tickets', $feedbackOnOpenTickets, $feedbackOnOpenTickets === 0);
        }
    }

    /** Installation & commissioning integrity: checklist, acceptance, custody, warranty and field-service links. */
    private function installations(): void
    {
        $this->line('Installations seeded (>=1)', DB::table('equipment_installations')->count(), DB::table('equipment_installations')->count() >= 1);

        $unresolved = DB::table('equipment_installations as i')
            ->whereNotNull('i.installed_at')
            ->where('i.commissioning_status', 'passed')
            ->where(function (Builder $query): void {
                $query->whereExists(function (Builder $checks): void {
                    $checks->selectRaw('1')->from('equipment_installation_checks as c')
                        ->whereColumn('c.equipment_installation_id', 'i.id')
                        ->whereNotIn('c.result', ['passed', 'not_applicable']);
                })->orWhereNotExists(function (Builder $checks): void {
                    $checks->selectRaw('1')->from('equipment_installation_checks as c')
                        ->whereColumn('c.equipment_installation_id', 'i.id');
                });
            })
            ->count();
        $this->line('Commissioned installations without a fully passed checklist', $unresolved, $unresolved === 0);

        $acceptedWithoutCommissioning = DB::table('equipment_installations')
            ->where('customer_acceptance_status', 'accepted')
            ->where('commissioning_status', '!=', 'passed')
            ->count();
        $this->line('Accepted installations without passed commissioning', $acceptedWithoutCommissioning, $acceptedWithoutCommissioning === 0);

        $custodyMismatch = DB::table('equipment_installations as i')
            ->join('maintenance_records as m', 'm.id', '=', 'i.maintenance_record_id')
            ->join('serialized_inventory_units as u', 'u.id', '=', 'i.serialized_inventory_unit_id')
            ->where(function (Builder $query): void {
                $query->where('u.custody_type', '!=', 'customer')
                    ->orWhereColumn('u.custody_reference_id', '!=', 'm.customer_id')
                    ->orWhereColumn('m.serialized_inventory_unit_id', '!=', 'i.serialized_inventory_unit_id');
            })
            ->count();
        $this->line('Installed equipment not in the request customer custody', $custodyMismatch, $custodyMismatch === 0);

        $warrantyMismatch = DB::table('equipment_installations as i')
            ->join('maintenance_records as m', 'm.id', '=', 'i.maintenance_record_id')
            ->join('warranty_entitlements as w', function (JoinClause $join): void {
                $join->on('w.serialized_inventory_unit_id', '=', 'i.serialized_inventory_unit_id')
                    ->on('w.customer_id', '=', 'm.customer_id');
            })
            ->where('w.state', 'active')
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $q) => $q->where('w.start_trigger', 'commissioning')->whereRaw('DATE(w.starts_on) <> DATE(i.commissioned_at)'))
                    ->orWhere(fn (Builder $q) => $q->where('w.start_trigger', 'installation')->whereRaw('DATE(w.starts_on) <> DATE(i.installed_at)'));
            })
            ->count();
        $this->line('Warranty start dates not matching their installation trigger', $warrantyMismatch, $warrantyMismatch === 0);

        $unlinkedVisits = DB::table('equipment_installations as i')
            ->join('maintenance_records as m', 'm.id', '=', 'i.maintenance_record_id')
            ->where('m.description', 'like', DemoInstallationSeeder::Marker.'%')
            ->whereNotExists(function (Builder $visits): void {
                $visits->selectRaw('1')->from('service_appointments as a')
                    ->join('maintenance_tasks as t', 't.id', '=', 'a.maintenance_task_id')
                    ->whereColumn('t.maintenance_record_id', 'm.id');
            })
            ->count();
        $this->line('Demo installations without a field-service visit on the same work', $unlinkedVisits, $unlinkedVisits === 0);
    }

    /** Calibration integrity: recorded measurements, certificates, failures and schedule links. */
    private function calibrations(): void
    {
        $this->line('Calibrations seeded (>=1)', DB::table('equipment_calibrations')->count(), DB::table('equipment_calibrations')->count() >= 1);

        $passedWithBadMeasurements = DB::table('equipment_calibrations as c')
            ->whereIn('c.result', ['passed', 'passed_with_adjustment'])
            ->where(function (Builder $query): void {
                $query->whereExists(function (Builder $measurements): void {
                    $measurements->selectRaw('1')->from('equipment_calibration_measurements as m')
                        ->whereColumn('m.equipment_calibration_id', 'c.id')
                        ->where('m.is_required', true)
                        ->whereIn('m.result', ['pending', 'failed']);
                })->orWhereNotExists(function (Builder $measurements): void {
                    $measurements->selectRaw('1')->from('equipment_calibration_measurements as m')
                        ->whereColumn('m.equipment_calibration_id', 'c.id');
                });
            })
            ->count();
        $this->line('Passed calibrations without fully passing required measurements', $passedWithBadMeasurements, $passedWithBadMeasurements === 0);

        $failedWithoutReason = DB::table('equipment_calibrations')->where('result', 'failed')->whereNull('failure_reason')->count();
        $this->line('Failed calibrations without a failure reason', $failedWithoutReason, $failedWithoutReason === 0);

        $unitMismatch = DB::table('equipment_calibrations as c')
            ->join('maintenance_records as m', 'm.id', '=', 'c.maintenance_record_id')
            ->where(function (Builder $query): void {
                $query->where('m.maintenance_kind', '!=', 'calibration')
                    ->orWhereColumn('m.serialized_inventory_unit_id', '!=', 'c.serialized_inventory_unit_id');
            })
            ->count();
        $this->line('Calibrations not matching their calibration request equipment', $unitMismatch, $unitMismatch === 0);

        $staleNextDue = DB::table('equipment_calibrations')
            ->whereNotNull('next_calibration_due_on')
            ->whereRaw('DATE(next_calibration_due_on) <= DATE(calibrated_at)')
            ->count();
        $this->line('Calibrations whose next due date is not after the calibration date', $staleNextDue, $staleNextDue === 0);

        $certificateless = DB::table('equipment_calibrations')
            ->where('maintenance_record_id', '>', 0)
            ->whereIn('result', ['passed', 'passed_with_adjustment'])
            ->whereNull('certificate_number')
            ->whereIn('maintenance_record_id', DB::table('maintenance_records')->where('description', 'like', DemoCalibrationSeeder::Marker.'%')->pluck('id'))
            ->count();
        $this->line('Demo passed calibrations without a certificate', $certificateless, $certificateless === 0);

        $schedules = DB::table('maintenance_schedules')->where('maintenance_kind', 'calibration')->where('is_active', true)->count();
        $this->line('Active calibration schedules (>=1)', $schedules, $schedules >= 1);

        $followUp = DB::table('equipment_calibrations as c')
            ->join('maintenance_records as f', 'f.id', '=', 'c.follow_up_maintenance_record_id')
            ->where('c.result', 'failed')
            ->where('f.maintenance_kind', 'corrective')
            ->count();
        $this->line('Failed calibrations with a follow-up repair request (>=1)', $followUp, $followUp >= 1);
    }

    /** Loaner and supplier-repair integrity: custody follows the loan / RMA status. */
    private function continuity(): void
    {
        $this->line('Equipment loans seeded (>=1)', DB::table('equipment_loans')->count(), DB::table('equipment_loans')->count() >= 1);

        $issuedOutsideCustomer = DB::table('equipment_loans as l')
            ->join('serialized_inventory_units as u', 'u.id', '=', 'l.loaner_serialized_inventory_unit_id')
            ->where('l.status', 'issued')
            ->where(function (Builder $query): void {
                $query->where('u.custody_type', '!=', 'customer')->orWhereColumn('u.custody_reference_id', '!=', 'l.customer_id');
            })
            ->count();
        $this->line('Issued loaners not in the borrowing customer custody', $issuedOutsideCustomer, $issuedOutsideCustomer === 0);

        $returnedNotInWarehouse = DB::table('equipment_loans as l')
            ->join('serialized_inventory_units as u', 'u.id', '=', 'l.loaner_serialized_inventory_unit_id')
            ->where('l.status', 'returned')
            ->where('u.custody_type', '!=', 'warehouse')
            ->count();
        $this->line('Returned loaners not back in a warehouse', $returnedNotInWarehouse, $returnedNotInWarehouse === 0);

        $doubleBooked = DB::table('equipment_loans')
            ->select('loaner_serialized_inventory_unit_id')
            ->whereIn('status', ['reserved', 'issued'])
            ->groupBy('loaner_serialized_inventory_unit_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        $this->line('Loaners with more than one active loan', $doubleBooked, $doubleBooked === 0);

        $issuedWithoutMovement = DB::table('equipment_loans')->where('status', 'issued')->whereNull('issue_inventory_movement_id')->count();
        $this->line('Issued loans without an inventory movement', $issuedWithoutMovement, $issuedWithoutMovement === 0);

        $this->line('Supplier repairs seeded (>=1)', DB::table('maintenance_external_repairs')->count(), DB::table('maintenance_external_repairs')->count() >= 1);

        $atSupplierWrongCustody = DB::table('maintenance_external_repairs as r')
            ->join('serialized_inventory_units as u', 'u.id', '=', 'r.serialized_inventory_unit_id')
            ->whereIn('r.status', ['shipped_to_supplier', 'received_by_supplier', 'repairing', 'repaired', 'replacement_approved'])
            ->where('u.custody_type', '!=', 'supplier')
            ->count();
        $this->line('Supplier repairs in progress whose unit is not in supplier custody', $atSupplierWrongCustody, $atSupplierWrongCustody === 0);

        $openOnClosedRequest = DB::table('maintenance_external_repairs as r')
            ->join('maintenance_records as m', 'm.id', '=', 'r.maintenance_record_id')
            ->whereNotIn('r.status', ['replacement_received', 'returned_to_company', 'cancelled'])
            ->whereIn('m.status', ['closed', 'cancelled'])
            ->count();
        $this->line('Open supplier repairs on closed or cancelled requests', $openOnClosedRequest, $openOnClosedRequest === 0);
    }

    private function inventoryDashboard(): void
    {
        $value = DemoContext::floatOf(DB::table('inventory_stocks')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.product_variant_id')
            ->selectRaw('COALESCE(SUM(inventory_stocks.available_quantity * COALESCE(product_variants.cost_price, 0)), 0) as t')
            ->value('t'));
        $this->line('Stock value (AED, target 70k-140k)', number_format($value, 2), $value >= 70000 && $value <= 140000);

        foreach (DB::table('inventory_stocks')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_stocks.warehouse_id')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.product_variant_id')
            ->where('warehouses.code', 'like', 'WH-%')
            ->groupBy('warehouses.name')
            ->selectRaw('warehouses.name as n, SUM(inventory_stocks.available_quantity * product_variants.cost_price) as v')
            ->orderByDesc('v')
            ->get() as $row) {
            $this->line('  warehouse '.$this->text($row->n), number_format(DemoContext::floatOf($row->v), 2), true);
        }

        $needs = DB::table('inventory_stocks as s')
            ->where(function (Builder $q): void {
                $q->where('s.available_quantity', '<=', 0)->orWhereExists(function (Builder $e): void {
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
        $this->line('Quote->order conversion % (target 35-60)', ($kpis['conversion_percent'] ?? 'n/a').' ('.$kpis['conversion_numerator'].'/'.$kpis['conversion_denominator'].')', ($kpis['conversion_percent'] ?? 0) >= 35 && $kpis['conversion_percent'] <= 60);

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

        $pendingConfirmation = fn (Builder $q): Builder => $q->select(DB::raw(1))->from('supplier_confirmations as c')->whereColumn('c.purchase_order_id', 'purchase_orders.id')->where('c.confirmation_status', 'pending');
        $stages = [
            'Approval' => DB::table('purchase_orders')->where('status', 'pending_approval')->count(),
            'Ready to send' => DB::table('purchase_orders')->where('status', 'accepted')->whereNull('sent_at')->count(),
            'Supplier' => DB::table('purchase_orders')->whereNotNull('sent_at')->whereExists($pendingConfirmation)->count(),
            'Receiving' => DB::table('purchase_orders')->whereNotNull('sent_at')->whereIn('status', ['accepted', 'partially_received'])->whereNotExists($pendingConfirmation)->count(),
            'Accounting' => DB::table('purchase_orders')->where('status', 'received')->where(function (Builder $q): void {
                $q->whereNotExists(fn (Builder $e) => $e->select(DB::raw(1))->from('bills')->whereColumn('bills.purchase_order_id', 'purchase_orders.id'))
                    ->orWhereExists(fn (Builder $e) => $e->select(DB::raw(1))->from('bills')->whereColumn('bills.purchase_order_id', 'purchase_orders.id')->whereNotIn('bills.status', ['paid', 'cancelled']));
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

        $payables = DemoContext::floatOf(DB::table('bills')->whereIn('status', ['approved', 'partially_paid'])->selectRaw('COALESCE(SUM(total_amount - amount_paid), 0) as t')->value('t'));
        $this->line('Payables outstanding AED (target 20k-45k)', number_format($payables, 2), $payables >= 20000 && $payables <= 45000);
        $owing = DB::table('bills')->whereIn('status', ['approved', 'partially_paid'])->whereRaw('total_amount - amount_paid > 0')->distinct()->count(DB::raw('COALESCE(supplier_id, resolved_supplier_id)'));
        $this->line('Suppliers with open bills (>=4)', $owing, $owing >= 4);
        $overdueBills = DB::table('bills')->whereIn('status', ['approved', 'partially_paid'])->whereDate('due_date', '<', '2026-10-03')->count();
        $this->line('Overdue bills (>=2)', $overdueBills, $overdueBills >= 2);

        $tax = app(TaxRegisterService::class)->period(CarbonImmutable::parse('2026-09-04'), CarbonImmutable::parse('2026-10-03'));
        $this->line('Net tax position (non-zero)', json_encode(array_map(fn (string $v): string => $v, $tax)), (float) $tax['net_position'] !== 0.0);

        $awaiting = DB::table('journal_entries')->where('status', 'draft')->count() + DB::table('bills')->where('status', 'draft')->count();
        $this->line('Awaiting action: draft JEs + draft bills (>=5)', $awaiting, $awaiting >= 5);

        $days = DB::table('journal_entries')->where('status', 'posted')->whereBetween('entry_date', ['2026-09-04', '2026-10-03'])->selectRaw('COUNT(DISTINCT entry_date) d')->value('d');
        $this->line('Posted-journal days (>=10)', $days, $days >= 10);
        $journals = DB::table('journal_entries')->whereIn('status', ['posted', 'draft'])->count();
        $this->line('Journal entries (target 30-45)', $journals, $journals >= 30 && $journals <= 45);

        $period = DB::table('fiscal_periods')->where('is_closed', false)->orderBy('starts_at')->first();
        $checks = $period === null ? collect() : DB::table('fiscal_period_close_checks')->where('fiscal_period_id', $period->id)->orderByDesc('id')->get()->unique('check_key');
        $periodName = $period === null ? null : ($period->name ?? null);
        $this->line('Period readiness ('.(is_string($periodName) ? $periodName : 'n/a').')', $checks->where('passed', 1)->count().' passed / '.$checks->where('passed', 0)->count().' need attention', $checks->where('passed', 1)->count() >= 3 && $checks->where('passed', 0)->count() >= 1);
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
                $rows[] = [$label, $this->text($row->s), DemoContext::intOf($row->c), 'PASS'];
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
        $this->command->getOutput()->writeln(sprintf('  %-58s %s  %s', $label, $mark, $this->text($value)));
    }

    /** Scalar database/report value as display text (NULL and non-scalars read as empty). */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            $this->command->getOutput()->writeln('  <error>ERROR</error> '.$throwable->getMessage());
        }
    }
}
