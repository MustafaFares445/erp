<?php

declare(strict_types=1);

use App\Enums\InventoryReportType;
use App\Enums\SalesPermission;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\TaxRegisterService;
use App\Services\Crm\CustomerTimelineService;
use App\Services\Inventory\InventoryLotReconciliationService;
use App\Services\Inventory\InventoryReportService;
use App\Services\Sales\SalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * WP-4.1 (PHASE_4_PLAN.md §1): wall-clock and query-count budgets for the
 * report and reconciliation surfaces, run against production-scale volume.
 *
 * These only run against a database seeded by `PerformanceBenchmarkSeeder` —
 * `php artisan db:seed --class=Database\Seeders\PerformanceBenchmarkSeeder` —
 * and are skipped otherwise so CI (which never seeds that volume) stays fast.
 * They deliberately do not use RefreshDatabase: the whole point is to measure
 * against the large, already-seeded dataset.
 *
 * The budgets below are first-pass ceilings, not tuned targets — per WP-4.1,
 * "the budget is the deliverable, the optimisation is whatever meets it".
 * Tighten them once a real baseline has been measured on production-like
 * hardware.
 */
const SEEDED_VOLUME_FLOOR = 10_000;

function benchmarkSeeded(): bool
{
    try {
        return Invoice::query()->count() >= SEEDED_VOLUME_FLOOR;
    } catch (Throwable) {
        // No schema at all (e.g. this file run standalone without the suite's
        // migrated database) counts as "not seeded" rather than a hard failure.
        return false;
    }
}

/** @return array{seconds: float, queries: int} */
function measure(Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $start = microtime(true);

    $callback();

    $seconds = microtime(true) - $start;
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    return ['seconds' => $seconds, 'queries' => $queries];
}

it('keeps AccountsReceivableService::aging within budget', function (): void {
    $result = measure(fn () => app(AccountsReceivableService::class)->aging());

    expect($result['seconds'])->toBeLessThan(5.0)
        ->and($result['queries'])->toBeLessThan(50);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps AccountsReceivableService::customerDetail within budget', function (): void {
    $customer = CustomerProfile::query()->inRandomOrder()->firstOrFail();

    $result = measure(fn () => app(AccountsReceivableService::class)->customerDetail($customer));

    expect($result['seconds'])->toBeLessThan(2.0)
        ->and($result['queries'])->toBeLessThan(50);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps SalesReportService::customerRevenue within budget', function (): void {
    $result = measure(fn () => app(SalesReportService::class)->customerRevenue());

    expect($result['seconds'])->toBeLessThan(5.0)
        ->and($result['queries'])->toBeLessThan(20);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps SalesReportService::invoicedNotCollected within budget', function (): void {
    $result = measure(fn () => app(SalesReportService::class)->invoicedNotCollected());

    expect($result['seconds'])->toBeLessThan(5.0)
        ->and($result['queries'])->toBeLessThan(20);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps InventoryReportService movement listing within budget', function (): void {
    $result = measure(function (): void {
        app(InventoryReportService::class)
            ->query(InventoryReportType::Movements)
            ->limit(100)
            ->get();
    });

    expect($result['seconds'])->toBeLessThan(2.0)
        ->and($result['queries'])->toBeLessThan(10);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps CustomerTimelineService::timeline within budget', function (): void {
    $actor = User::factory()->admin()->create();
    $actor->givePermissionTo(Permission::findOrCreate(SalesPermission::InvoiceView->value, 'web'));
    $customer = CustomerProfile::query()->inRandomOrder()->firstOrFail();

    $result = measure(fn () => app(CustomerTimelineService::class)
        ->timeline($customer, $actor, from: null, until: null, types: ['invoice']));

    expect($result['seconds'])->toBeLessThan(2.0)
        ->and($result['queries'])->toBeLessThan(10);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps InventoryLotReconciliationService::inspect within budget', function (): void {
    $result = measure(fn () => app(InventoryLotReconciliationService::class)->inspect());

    expect($result['seconds'])->toBeLessThan(30.0);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps FinancialReportService::trialBalance within budget', function (): void {
    $from = CarbonImmutable::now()->subYears(4);
    $to = CarbonImmutable::now();

    $result = measure(fn () => app(FinancialReportService::class)->trialBalance($from, $to));

    expect($result['seconds'])->toBeLessThan(5.0)
        ->and($result['queries'])->toBeLessThan(10);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps FinancialReportService::generalLedger within budget', function (): void {
    $from = CarbonImmutable::now()->subYears(4);
    $to = CarbonImmutable::now();

    $result = measure(fn () => app(FinancialReportService::class)->generalLedger($from, $to, null, 25));

    expect($result['seconds'])->toBeLessThan(5.0)
        ->and($result['queries'])->toBeLessThan(10);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps FinancialReportService::profitAndLoss within budget', function (): void {
    $from = CarbonImmutable::now()->subYears(4);
    $to = CarbonImmutable::now();

    $result = measure(fn () => app(FinancialReportService::class)->profitAndLoss($from, $to));

    expect($result['seconds'])->toBeLessThan(5.0)
        ->and($result['queries'])->toBeLessThan(10);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps FinancialReportService::balanceSheet within budget', function (): void {
    $result = measure(fn () => app(FinancialReportService::class)->balanceSheet(CarbonImmutable::now()));

    expect($result['seconds'])->toBeLessThan(5.0)
        ->and($result['queries'])->toBeLessThan(10);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps TaxRegisterService::period within budget', function (): void {
    $from = CarbonImmutable::now()->subYears(4);
    $to = CarbonImmutable::now();

    $result = measure(fn () => app(TaxRegisterService::class)->period($from, $to));

    expect($result['seconds'])->toBeLessThan(5.0);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps TaxRegisterService::reconciliation within budget', function (): void {
    $from = CarbonImmutable::now()->subYears(4);
    $to = CarbonImmutable::now();

    $result = measure(fn () => app(TaxRegisterService::class)->reconciliation($from, $to));

    expect($result['seconds'])->toBeLessThan(5.0);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps AccountsPayableService::summary within budget', function (): void {
    $result = measure(fn () => app(AccountsPayableService::class)->summary());

    expect($result['seconds'])->toBeLessThan(5.0)
        ->and($result['queries'])->toBeLessThan(20);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');

it('keeps AccountsPayableService::aging within budget', function (): void {
    $result = measure(fn () => app(AccountsPayableService::class)->aging());

    expect($result['seconds'])->toBeLessThan(5.0)
        ->and($result['queries'])->toBeLessThan(20);
})->skip(fn (): bool => ! benchmarkSeeded(), 'Requires PerformanceBenchmarkSeeder volume.');
