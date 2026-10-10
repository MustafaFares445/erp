<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlanTaskStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\TicketStatus;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Supplier;
use Database\Seeders\Demo\UnifiedDevelopmentSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One development database, with safe idempotent seeding and a failing QA gate.
 */
final class SeedUnifiedDemoCommand extends Command
{
    protected $signature = 'demo:seed
        {--anchor= : Anchor YYYY-MM-DD (default: today)}
        {--dry-run : Show the target without modifying it}
        {--verify-only : Run strict data verification without seeding}
        {--skip-verify : Only seed; do not run the strict verification}';

    protected $description = 'Populate and verify the unified, synthetic local IERP development company.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Demo data is permitted only in APP_ENV=local or testing.');

            return self::FAILURE;
        }

        $connection = DB::getDefaultConnection();
        $hostValue = config("database.connections.{$connection}.host", '');
        $host = is_string($hostValue) ? $hostValue : '';
        $databaseValue = config("database.connections.{$connection}.database", '');
        $database = is_string($databaseValue) ? $databaseValue : '';

        if (! in_array($connection, ['mysql', 'sqlite'], true)
            || ($connection === 'mysql' && ! in_array(mb_strtolower($host), ['127.0.0.1', 'localhost', '::1'], true))) {
            $this->error('Refusing to seed a non-local database connection.');

            return self::FAILURE;
        }

        $rawOption = $this->option('anchor');
        $rawAnchor = is_string($rawOption) && $rawOption !== '' ? $rawOption : now()->toDateString();

        try {
            $anchor = Carbon::createFromFormat('!Y-m-d', $rawAnchor);
            if (! $anchor instanceof Carbon || $anchor->format('Y-m-d') !== $rawAnchor) {
                throw new \InvalidArgumentException('Invalid anchor date.');
            }
        } catch (Throwable) {
            $this->error('Use --anchor=YYYY-MM-DD with a real calendar date.');

            return self::FAILURE;
        }

        $this->info(sprintf('Unified development database: %s / %s; anchor %s', $connection, $database, $anchor->toDateString()));

        if ($this->option('dry-run')) {
            $this->line('No changes. A full run executes the canonical multi-module story, then 112 synthetic product families, 336 variants, 124 customer accounts, 140 leads and 330 additional quotations.');

            return self::SUCCESS;
        }

        try {
            if (! $this->option('verify-only')) {
                config(['demo.unified.anchor' => $anchor->toDateString()]);
                $status = $this->call('db:seed', ['--class' => UnifiedDevelopmentSeeder::class, '--force' => true]);
                if ($status !== self::SUCCESS) {
                    $this->error('Unified development seeding did not finish successfully.');

                    return self::FAILURE;
                }
            }

            return $this->option('skip-verify') ? self::SUCCESS : $this->verify();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function verify(): int
    {
        /** @var array<string, array{int,int}> $checks */
        $checks = [
            'demo customers' => [CustomerProfile::query()->where('customer_code', 'like', 'DEMO-CUST-%')->count(), 140],
            'demo suppliers' => [Supplier::query()->where('code', 'like', 'DEMO-SUP-%')->count(), 25],
            'demo products (core + realistic)' => [Product::query()->where('name', 'like', '[Demo] %')->count() + ProductVariant::query()->where('sku', 'like', 'DEMO-P%')->distinct()->count('product_id'), 130],
            'demo product variants' => [ProductVariant::query()->where('sku', 'like', 'DEMO-%')->count(), 370],
            'leads' => [Lead::query()->count(), 150],
            'quotations' => [Quotation::query()->count(), 350],
            'purchase orders' => [DB::table('purchase_orders')->count(), 160],
            'historical supplier commitments' => [DB::table('purchase_orders')->where('notes', 'like', '[DEMO-HISTORY] Procurement %')->count(), 156],
            'historical CRM leads' => [Lead::query()->where('email', 'like', 'history.lead%@prospect.test')->count(), 140],
            'historical quotations' => [Quotation::query()->where('notes', 'like', '[DEMO-HISTORY] Quotation %')->count(), 330],
            'historical support tickets' => [DB::table('tickets')->where('title', 'like', '[DEMO-HISTORY] Support case %')->count(), 120],
            'historical field plans' => [DB::table('sales_plans')->where('name', 'like', '[DEMO-HISTORY] %')->count(), 72],
            'historical field tasks' => [DB::table('plan_tasks')->where('title', 'like', '[DEMO-HISTORY] %')->count(), 504],
            'historical field visits' => [DB::table('customer_visits')
                ->whereIn('plan_task_id', DB::table('plan_tasks')->where('title', 'like', '[DEMO-HISTORY] %')->select('id'))
                ->count(), 360],
            'realistic bilingual product families' => [DB::table('products')->where('name', 'like', '[Demo] %')
                ->whereColumn('name', '!=', 'name_ar')->count(), 112],
            'inventory movements' => [DB::table('inventory_movements')->count(), 550],
            'sales fulfilment journeys' => [DB::table('orders')->where('notes', 'like', '[DEMO-FULFILL] %')->count(), 230],
            'sales orders' => [DB::table('orders')->count(), 250],
            'invoices' => [DB::table('invoices')->count(), 250],
            'payments' => [DB::table('payments')->count(), 165],
            'posted journals' => [DB::table('journal_entries')->where('status', 'posted')->count(), 300],
            'support tickets' => [DB::table('tickets')->count(), 10],
            'maintenance records' => [DB::table('maintenance_records')->count(), 75],
            'historical maintenance records' => [DB::table('maintenance_records')->where('description', 'like', '[DEMO-HISTORY] Maintenance %')->count(), 60],
        ];

        $failed = 0;
        foreach ($checks as $label => [$actual, $minimum]) {
            $ok = $actual >= $minimum;
            $this->line(sprintf('%-42s %s %d / %d', $label, $ok ? 'PASS' : 'FAIL', $actual, $minimum));
            $failed += (int) ! $ok;
        }

        $monthCount = Quotation::query()
            ->where('notes', 'like', '[DEMO-HISTORY] Quotation %')
            ->get(['issue_date'])
            ->map(fn (Quotation $q): string => is_string($q->getRawOriginal('issue_date')) ? mb_substr($q->getRawOriginal('issue_date'), 0, 7) : '')
            ->unique()->count();
        $this->line(sprintf('historical quotation months: %d / 12', $monthCount));
        $failed += (int) ($monthCount < 12);

        $monthCoverage = [
            'historical purchase-order months' => DB::table('purchase_orders')->where('notes', 'like', '[DEMO-HISTORY] Procurement %')
                ->pluck('ordered_at')->map(fn (mixed $date): string => is_string($date) ? mb_substr($date, 0, 7) : '')->unique()->count(),
            'historical employee-plan months' => DB::table('sales_plans')->where('name', 'like', '[DEMO-HISTORY] %')
                ->pluck('month')->map(fn (mixed $date): string => is_string($date) ? mb_substr($date, 0, 7) : '')->unique()->count(),
            'historical support-ticket months' => DB::table('tickets')->where('title', 'like', '[DEMO-HISTORY] Support case %')
                ->pluck('created_at')->map(fn (mixed $date): string => is_string($date) ? mb_substr($date, 0, 7) : '')->unique()->count(),
        ];

        foreach ($monthCoverage as $label => $count) {
            $ok = $count >= 12;
            $this->line(sprintf('%-42s %s %d / 12', $label, $ok ? 'PASS' : 'FAIL', $count));
            $failed += (int) ! $ok;
        }

        $requiredStatusCases = [
            'tickets' => array_map(static fn (TicketStatus $s): string => $s->value, TicketStatus::cases()),
            'purchase_orders' => array_map(static fn (PurchaseOrderStatus $s): string => $s->value, PurchaseOrderStatus::cases()),
            'plan_tasks' => array_map(static fn (PlanTaskStatus $s): string => $s->value, PlanTaskStatus::cases()),
        ];
        foreach ($requiredStatusCases as $table => $expected) {
            $existing = array_map(static fn (mixed $s): string => is_string($s) ? $s : '',
                DB::table($table)->distinct()->pluck('status')->all());
            $missing = array_values(array_diff($expected, $existing));
            $this->line("{$table} status coverage: ".($missing === [] ? 'PASS' : 'FAIL '.implode(', ', $missing)));
            $failed += (int) ($missing !== []);
        }

        // E2E source chain: a demo sales order is complete only when a
        // real warehouse delivery reached Done, the corresponding AR invoice
        // was issued, and its PDF evidence exists in Spatie Media Library.
        $sales = Order::query()->where('notes', 'like', '[DEMO-FULFILL] %');
        $withoutDelivery = (clone $sales)->whereDoesntHave('deliveries',
            fn ($query) => $query->where('stage', 'done'))->count();
        $withoutInvoice = (clone $sales)->whereDoesntHave('invoices',
            fn ($query) => $query->whereNotNull('issued_at'))->count();
        $withoutPdf = Invoice::query()
            ->whereHas('order', fn ($query) => $query->where('notes', 'like', '[DEMO-FULFILL] %'))
            ->whereDoesntHave('media', fn ($query) => $query->where('collection_name', 'invoice-pdf'))
            ->count();

        foreach ([
            'sales orders lacking completed delivery' => $withoutDelivery,
            'sales orders lacking issued invoice' => $withoutInvoice,
            'issued sales invoices lacking PDF' => $withoutPdf,
        ] as $label => $broken) {
            $this->line("{$label}: ".($broken === 0 ? 'PASS' : "FAIL {$broken}"));
            $failed += (int) ($broken !== 0);
        }

        if (Schema::hasTable('inventory_stocks')) {
            $invalid = DB::table('inventory_stocks')->where(function (Builder $query): void {
                $query->where('on_hand_quantity', '<', 0)
                    ->orWhere('reserved_quantity', '<', 0)
                    ->orWhere('available_quantity', '<', -0.00001)
                    ->orWhereColumn('reserved_quantity', '>', 'on_hand_quantity');
            })->count();
            $this->line('negative or over-reserved stock: '.($invalid === 0 ? 'PASS' : "FAIL {$invalid}"));
            $failed += (int) ($invalid !== 0);
        }

        if (Schema::hasTable('journal_entry_lines')) {
            $unbalanced = DB::table('journal_entries as j')
                ->leftJoin('journal_entry_lines as l', 'j.id', '=', 'l.journal_entry_id')
                ->where('j.status', 'posted')
                ->groupBy('j.id')
                ->havingRaw('COUNT(l.id) = 0 OR ABS(COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0)) > 0.01')
                ->select('j.id')->count();
            $this->line('unbalanced posted journals: '.($unbalanced === 0 ? 'PASS' : "FAIL {$unbalanced}"));
            $failed += (int) ($unbalanced !== 0);
        }

        $missingSources = DB::table('sales_procurement_requirements')
            ->where('status', 'fulfilled')
            ->where(function (Builder $query): void {
                $query->whereNull('purchase_order_id')->orWhereNull('purchase_order_line_id');
            })->count();
        $this->line('fulfilled procurements without PO source: '.($missingSources === 0 ? 'PASS' : "FAIL {$missingSources}"));
        $failed += (int) ($missingSources !== 0);

        if ($failed > 0) {
            $this->error("Unified demo verification FAILED ({$failed} checks).");

            return self::FAILURE;
        }

        $this->info('Unified demo verification PASSED.');

        return self::SUCCESS;
    }
}
