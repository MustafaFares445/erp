<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Enums\ReconciliationScope;
use App\Models\ReconciliationRun;
use App\Services\Inventory\ReconciliationReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes reconciliation as a stock-backed inventory report', function (): void {
    expect(InventoryReportType::Reconciliation->sourcePermission())
        ->toBe(InventoryPermission::StockView)
        ->and(InventoryReportType::Reconciliation->requiresPricing())
        ->toBeFalse();
});

it('filters persisted reconciliation history without recalculating inventory', function (): void {
    ReconciliationRun::query()->create([
        'scope' => ReconciliationScope::InventoryLots,
        'invariant' => 'lot_balance_matches_ledger',
        'passed' => false,
        'divergence_count' => 2,
        'detail' => ['lot #10 differs', 'lot #12 differs'],
        'started_at' => '2026-09-03 08:00:00',
        'finished_at' => '2026-09-03 08:00:01',
        'trigger_source' => 'manual',
    ]);

    ReconciliationRun::query()->create([
        'scope' => ReconciliationScope::Receivables,
        'invariant' => 'receivable_balance_matches_subledger',
        'passed' => true,
        'divergence_count' => 0,
        'detail' => null,
        'started_at' => '2026-09-04 08:00:00',
        'finished_at' => '2026-09-04 08:00:01',
        'trigger_source' => 'period_close',
    ]);

    $service = app(ReconciliationReportService::class);

    $rows = $service->query([
        'scope' => ReconciliationScope::InventoryLots->value,
        'passed' => false,
        'from' => '2026-09-03',
        'until' => '2026-09-03',
    ])->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->invariant)->toBe('lot_balance_matches_ledger')
        ->and($service->divergences()->count())->toBe(1)
        ->and($service->hasPersistedRuns())->toBeTrue();
});

it('returns an explicit empty persisted state before reconciliation has ever run', function (): void {
    expect(app(ReconciliationReportService::class)->hasPersistedRuns())->toBeFalse();
});
