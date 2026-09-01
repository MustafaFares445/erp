<?php

declare(strict_types=1);

use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReturn;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryLotReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('passes when canonical lot balances reconcile to the aggregate condition grain', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $warehouse = Warehouse::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '10.000000',
    ]);
    InventoryConditionBalance::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->delete();

    foreach ([
        StockCondition::Saleable->value => ['10.000000', '0.000000'],
        StockCondition::Quarantine->value => ['0.000000', '0.000000'],
        StockCondition::Damaged->value => ['0.000000', '0.000000'],
    ] as $condition => [$onHand, $reserved]) {
        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition,
            'on_hand_base_quantity' => $onHand,
            'reserved_base_quantity' => $reserved,
        ]);
    }

    $lot = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create([
        'lot_number' => 'RECON-LOT',
        'expires_at' => null,
    ]);

    foreach ([
        StockCondition::Saleable->value => ['10.000000', '0.000000'],
        StockCondition::Quarantine->value => ['0.000000', '0.000000'],
        StockCondition::Damaged->value => ['0.000000', '0.000000'],
    ] as $condition => [$onHand, $reserved]) {
        InventoryLotBalance::query()->forceCreate([
            'inventory_lot_id' => $lot->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition,
            'on_hand_base_quantity' => $onHand,
            'reserved_base_quantity' => $reserved,
        ]);
    }

    $report = app(InventoryLotReconciliationService::class)->inspect();

    expect($report['errors'])->toBe([])
        ->and($report['checked_lot_balances'])->toBe(3)
        ->and($report['checked_aggregate_balances'])->toBe(3);
});

it('reports aggregate lot divergence without repairing it', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $warehouse = Warehouse::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '10.000000',
    ]);
    $aggregate = InventoryConditionBalance::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->where('stock_condition', StockCondition::Saleable->value)
        ->first();

    if (! $aggregate instanceof InventoryConditionBalance) {
        $aggregate = InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => StockCondition::Saleable,
            'on_hand_base_quantity' => '10.000000',
            'reserved_base_quantity' => '0.000000',
        ]);
    } else {
        $aggregate->forceFill([
            'on_hand_base_quantity' => '10.000000',
            'reserved_base_quantity' => '0.000000',
        ])->save();
    }

    $lot = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create([
        'lot_number' => 'BROKEN-LOT',
        'expires_at' => null,
    ]);
    $lotBalance = InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '9.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    foreach ([StockCondition::Quarantine, StockCondition::Damaged] as $condition) {
        InventoryConditionBalance::query()->firstOrCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition->value,
        ], [
            'on_hand_base_quantity' => '0.000000',
            'reserved_base_quantity' => '0.000000',
        ]);
        InventoryLotBalance::query()->forceCreate([
            'inventory_lot_id' => $lot->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition,
            'on_hand_base_quantity' => '0.000000',
            'reserved_base_quantity' => '0.000000',
        ]);
    }

    $report = app(InventoryLotReconciliationService::class)->inspect();

    expect($report['errors'])->not->toBeEmpty()
        ->and(collect($report['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'aggregate=10.000000')
                && str_contains($error, 'lots=9.000000'),
        ))->toBeTrue()
        ->and($lotBalance->refresh()->on_hand_base_quantity)->toBe('9.000000')
        ->and($aggregate->refresh()->on_hand_base_quantity)->toBe('10.000000');
});

it('reports incomplete schema before querying canonical lot tables', function (): void {
    Schema::shouldReceive('hasTable')
        ->andReturnUsing(fn (string $table): bool => $table !== 'inventory_lot_balances');

    $report = app(InventoryLotReconciliationService::class)->inspect();

    expect($report['checked_lot_balances'])->toBe(0)
        ->and($report['checked_aggregate_balances'])->toBe(0)
        ->and($report['checked_return_lines'])->toBe(0)
        ->and($report['checked_movements'])->toBe(0)
        ->and($report['errors'])->toHaveCount(1)
        ->and($report['errors'][0])->toContain('required migrations are incomplete')
        ->toContain('inventory_lot_balances');
});

it('detects partial movement UOM evidence without repairing the ledger', function (): void {
    $movement = InventoryMovement::factory()->create();

    DB::table('inventory_movements')
        ->where('id', $movement->getKey())
        ->update([
            'transaction_quantity' => '2.000000',
            'transaction_unit_id' => $movement->productVariant->unit_id,
            'conversion_factor_snapshot' => null,
            'base_quantity_delta' => null,
        ]);

    $report = app(InventoryLotReconciliationService::class)->inspect();

    expect($report['checked_movements'])->toBeGreaterThanOrEqual(1)
        ->and(collect($report['errors'])->contains(
            fn (string $error): bool => str_contains(
                $error,
                'Inventory movement '.$movement->getKey().' has a partial transaction-UOM snapshot.',
            ),
        ))->toBeTrue()
        ->and($movement->refresh()->conversion_factor_snapshot)->toBeNull()
        ->and($movement->base_quantity_delta)->toBeNull();
});

it('reports incomplete source, invalid conversion, and missing reversal context without rewriting it', function (): void {
    $variant = ProductVariant::factory()->create();
    $unitId = $variant->unit_id;

    if (! is_int($unitId)) {
        throw new LogicException('Product variant test fixtures require integer unit identifiers.');
    }

    $incompleteSource = InventoryMovement::factory()->for($variant, 'productVariant')->create();
    $incompleteLine = InventoryMovement::factory()->for($variant, 'productVariant')->create();
    $invalidConversion = InventoryMovement::factory()->for($variant, 'productVariant')->create([
        'quantity' => '2.000000',
    ]);
    $missingReversal = InventoryMovement::factory()->for($variant, 'productVariant')->create();

    DB::table('inventory_movements')->where('id', $incompleteSource->getKey())->update([
        'source_type' => 'inventory_operation',
        'source_id' => null,
    ]);
    DB::table('inventory_movements')->where('id', $incompleteLine->getKey())->update([
        'source_type' => 'inventory_operation',
        'source_id' => 10,
        'source_line_type' => 'inventory_operation_line',
        'source_line_id' => null,
    ]);
    DB::table('inventory_movements')->where('id', $invalidConversion->getKey())->update([
        'source_type' => 'inventory_operation',
        'source_id' => 11,
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $unitId,
        'conversion_factor_snapshot' => '2.000000',
        'base_quantity_delta' => '2.000000',
    ]);

    Schema::disableForeignKeyConstraints();
    try {
        DB::table('inventory_movements')->where('id', $missingReversal->getKey())->update([
            'source_type' => 'inventory_operation',
            'source_id' => 12,
            'reversal_of_movement_id' => 999_999,
        ]);
    } finally {
        Schema::enableForeignKeyConstraints();
    }

    $before = DB::table('inventory_movements')
        ->whereIn('id', [
            $incompleteSource->getKey(),
            $incompleteLine->getKey(),
            $invalidConversion->getKey(),
            $missingReversal->getKey(),
        ])
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => (array) $row)
        ->all();

    $report = app(InventoryLotReconciliationService::class)->inspect();

    expect(collect($report['errors'])->contains(
        fn (string $error): bool => str_contains($error, 'incomplete source-document reference'),
    ))->toBeTrue()
        ->and(collect($report['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'incomplete source-line reference'),
        ))->toBeTrue()
        ->and(collect($report['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'invalid transaction-UOM/base-quantity snapshot'),
        ))->toBeTrue()
        ->and(collect($report['errors'])->contains(
            fn (string $error): bool => str_contains($error, 'missing reversal origin'),
        ))->toBeTrue();

    $after = DB::table('inventory_movements')
        ->whereIn('id', [
            $incompleteSource->getKey(),
            $incompleteLine->getKey(),
            $invalidConversion->getKey(),
            $missingReversal->getKey(),
        ])
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => (array) $row)
        ->all();

    expect($after)->toBe($before);
});

it('fails the reconciliation command without rewriting corrupted ledger context', function (): void {
    $movement = InventoryMovement::factory()->create();

    DB::table('inventory_movements')
        ->where('id', $movement->getKey())
        ->update([
            'transaction_quantity' => '2.000000',
            'transaction_unit_id' => $movement->productVariant->unit_id,
            'conversion_factor_snapshot' => null,
            'base_quantity_delta' => null,
        ]);

    $before = DB::table('inventory_movements')->where('id', $movement->getKey())->first();
    $exitCode = Artisan::call('inventory:lots:reconcile');
    $after = DB::table('inventory_movements')->where('id', $movement->getKey())->first();

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('ledger movements')
        ->and((array) $after)->toBe((array) $before);
});

it('detects invalid posted return movement evidence without repairing it', function (): void {
    $return = InventoryReturn::factory()->create();
    $variant = ProductVariant::factory()->create();
    $line = $return->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
    ]);

    // Deliberately bypass model guards to simulate corrupted persisted data.
    DB::table('inventory_returns')
        ->where('id', $return->getKey())
        ->update([
            'status' => 'posted',
            'ready_at' => now()->subMinute(),
            'posted_at' => now(),
        ]);
    DB::table('inventory_return_lines')
        ->where('id', $line->getKey())
        ->update([
            'posted_base_quantity' => '1.000000',
            'posted_inventory_movement_id' => null,
        ]);

    $report = app(InventoryLotReconciliationService::class)->inspect();

    expect($report['checked_return_lines'])->toBe(1)
        ->and(collect($report['errors'])->contains(
            fn (string $error): bool => str_contains(
                $error,
                'Posted inventory return line '.$line->getKey(),
            ),
        ))->toBeTrue()
        ->and($line->refresh()->posted_inventory_movement_id)->toBeNull();
});
