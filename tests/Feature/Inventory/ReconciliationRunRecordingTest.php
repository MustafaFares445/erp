<?php

declare(strict_types=1);

use App\Enums\ReconciliationScope;
use App\Models\InventoryMovement;
use App\Models\ReconciliationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('persists each canonical inventory reconciliation invariant on a clean run', function (): void {
    $exitCode = Artisan::call('inventory:lots:reconcile');

    $runs = ReconciliationRun::query()
        ->where('scope', ReconciliationScope::InventoryLots->value)
        ->orderBy('invariant')
        ->get();

    expect($exitCode)->toBe(0)
        ->and($runs)->toHaveCount(7)
        ->and($runs->every(fn (ReconciliationRun $run): bool => $run->passed))->toBeTrue()
        ->and($runs->every(fn (ReconciliationRun $run): bool => $run->trigger_source === 'manual'))->toBeTrue();
});

it('persists the failing invariant and returns a non-zero exit code', function (): void {
    $movement = InventoryMovement::factory()->create();

    DB::table('inventory_movements')
        ->where('id', $movement->getKey())
        ->update([
            'transaction_quantity' => '2.000000',
            'transaction_unit_id' => $movement->productVariant->unit_id,
            'conversion_factor_snapshot' => null,
            'base_quantity_delta' => null,
        ]);

    $exitCode = Artisan::call('inventory:lots:reconcile');

    $run = ReconciliationRun::query()
        ->where('scope', ReconciliationScope::InventoryLots->value)
        ->where('invariant', 'movement_context_integrity')
        ->latest('id')
        ->sole();

    expect($exitCode)->toBe(1)
        ->and($run->passed)->toBeFalse()
        ->and($run->divergence_count)->toBeGreaterThanOrEqual(1)
        ->and(collect($run->detail)->contains(
            fn (string $error): bool => str_contains($error, 'partial transaction-UOM snapshot'),
        ))->toBeTrue();
});

it('marks scheduler-triggered reconciliation runs separately', function (): void {
    $exitCode = Artisan::call('inventory:lots:reconcile', ['--scheduled' => true]);

    expect($exitCode)->toBe(0)
        ->and(ReconciliationRun::query()
            ->where('scope', ReconciliationScope::InventoryLots->value)
            ->where('trigger_source', 'schedule')
            ->count())->toBe(7);
});
