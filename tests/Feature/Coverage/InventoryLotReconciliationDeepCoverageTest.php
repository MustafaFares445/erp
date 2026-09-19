<?php

declare(strict_types=1);

use App\Enums\InventoryReturnStatus;
use App\Enums\MovementType;
use App\Enums\ReservationStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryReservationAllocation;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryLotReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);
function deepReconErrors(): array
{
    return app(InventoryLotReconciliationService::class)->inspect()['errors'];
}

function deepReconContains(array $errors, string $needle): bool
{
    return collect($errors)->contains(
        static fn (string $error): bool => str_contains($error, $needle),
    );
}

it('covers lot aggregate reservation and alias divergence branches', function (): void {
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $warehouse = Warehouse::factory()->create();
    $canonical = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create();
    $alias = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create();
    $alias->forceFill(['canonical_inventory_lot_id' => $canonical->getKey()])->saveQuietly();

    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $alias->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '1.000000',
        'reserved_base_quantity' => '2.000000',
    ]);
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $canonical->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Damaged,
        'on_hand_base_quantity' => '3.000000',
        'reserved_base_quantity' => '1.000000',
    ]);
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $canonical->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '10.000000',
        'reserved_base_quantity' => '5.000000',
    ]);

    $reservation = InventoryReservation::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => ReservationStatus::Active,
        'base_quantity' => '4.000000',
    ]);
    InventoryReservationAllocation::factory()->create([
        'inventory_reservation_id' => $reservation->getKey(),
        'inventory_lot_id' => $canonical->getKey(),
        'base_quantity' => '3.000000',
    ]);
    $unmaterializedLot = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create();
    InventoryReservationAllocation::factory()->create([
        'inventory_reservation_id' => $reservation->getKey(),
        'inventory_lot_id' => $unmaterializedLot->getKey(),
        'base_quantity' => '1.000000',
    ]);

    $errors = deepReconErrors();

    expect(deepReconContains($errors, 'legacy alias lot'))->toBeTrue()
        ->and(deepReconContains($errors, 'reserves more than saleable on-hand'))->toBeTrue()
        ->and(deepReconContains($errors, 'reservation in damaged condition'))->toBeTrue()
        ->and(deepReconContains($errors, 'quantity without an aggregate condition balance'))->toBeTrue()
        ->and(deepReconContains($errors, 'reserved=5.000000 but active allocations=3.000000'))->toBeTrue()
        ->and(deepReconContains($errors, 'has no materialized saleable reservation'))->toBeTrue()
        ->and(deepReconContains($errors, 'Legacy lot aliases still own materialized lot balances.'))->toBeTrue();
});

it('covers serialized custody divergence branches', function (): void {
    $warehouse = Warehouse::factory()->create();
    $serialVariant = ProductVariant::factory()->machine()->create();
    $otherVariant = ProductVariant::factory()->machine()->create();
    $lot = InventoryLot::factory()->canonical()->for($otherVariant, 'productVariant')->create();
    SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $serialVariant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'inventory_lot_id' => $lot->getKey(),
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Saleable,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $serialVariant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'inventory_lot_id' => $lot->getKey(),
        'custody_type' => SerializedCustodyType::Customer,
        'stock_condition' => StockCondition::Saleable,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $serialVariant->getKey(),
        'warehouse_id' => null,
        'inventory_lot_id' => $lot->getKey(),
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Saleable,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $serialVariant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'inventory_lot_id' => $lot->getKey(),
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Disposed,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    $canonical = InventoryLot::factory()->canonical()->for($serialVariant, 'productVariant')->create();
    $alias = InventoryLot::factory()->canonical()->for($serialVariant, 'productVariant')->create();
    $alias->forceFill(['canonical_inventory_lot_id' => $canonical->getKey()])->saveQuietly();
    SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $serialVariant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'inventory_lot_id' => $alias->getKey(),
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Saleable,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);

    $errors = deepReconErrors();
    expect(deepReconContains($errors, 'does not reference its canonical variant lot identity'))->toBeTrue()
        ->and(deepReconContains($errors, 'non-warehouse custody but retains warehouse'))->toBeTrue()
        ->and(deepReconContains($errors, 'invalid warehouse custody/condition'))->toBeTrue()
        ->and(deepReconContains($errors, 'no matching positive lot balance'))->toBeTrue()
        ->and(deepReconContains($errors, 'serial custody count'))->toBeTrue();
});

it('covers missing identities valid serial aggregates and return evidence variants', function (): void {
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::statement('PRAGMA ignore_check_constraints = ON');
    } else {
        Schema::disableForeignKeyConstraints();
    }

    $warehouse = Warehouse::factory()->create();
    $batchVariant = ProductVariant::factory()->expiryMaterial()->create();
    $lot = InventoryLot::factory()->canonical()->for($batchVariant, 'productVariant')->create();
    $missingLotBalance = InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '1.000000',
        'reserved_base_quantity' => '0.000000',
    ]);
    DB::table('inventory_lot_balances')->where('id', $missingLotBalance->getKey())->update([
        'inventory_lot_id' => 999_991,
    ]);

    $negativeLot = InventoryLot::factory()->canonical()->for($batchVariant, 'productVariant')->create();
    $negativeBalance = InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $negativeLot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '1.000000',
        'reserved_base_quantity' => '0.000000',
    ]);
    DB::table('inventory_lot_balances')->where('id', $negativeBalance->getKey())->update([
        'on_hand_base_quantity' => '-1.000000',
    ]);

    $serialVariant = ProductVariant::factory()->machine()->create();
    $serialLot = InventoryLot::factory()->canonical()->for($serialVariant, 'productVariant')->create();
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $serialLot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '1.000000',
        'reserved_base_quantity' => '0.000000',
    ]);
    InventoryConditionBalance::query()->forceCreate([
        'product_variant_id' => $serialVariant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '1.000000',
        'reserved_base_quantity' => '0.000000',
    ]);
    SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $serialVariant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'inventory_lot_id' => $serialLot->getKey(),
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Saleable,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    $missingLotUnit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $serialVariant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'inventory_lot_id' => $serialLot->getKey(),
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Saleable,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    DB::table('serialized_inventory_units')->where('id', $missingLotUnit->getKey())->update([
        'inventory_lot_id' => 999_992,
    ]);

    $orphanReturn = InventoryReturn::factory()->create();
    $orphanLine = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $orphanReturn->getKey(),
    ]);
    DB::table('inventory_return_lines')->where('id', $orphanLine->getKey())->update([
        'inventory_return_id' => 999_993,
    ]);
    $unpostedReturn = InventoryReturn::factory()->create();
    $unpostedLine = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $unpostedReturn->getKey(),
    ]);
    $evidenceMovement = InventoryMovement::factory()->for($unpostedLine->productVariant, 'productVariant')->create();
    DB::table('inventory_return_lines')->where('id', $unpostedLine->getKey())->update([
        'posted_base_quantity' => '1.000000',
        'posted_inventory_movement_id' => $evidenceMovement->getKey(),
    ]);

    $wrongReturn = InventoryReturn::factory()->create();
    $wrongLine = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $wrongReturn->getKey(),
    ]);
    $wrongMovement = InventoryMovement::factory()->return()->for($wrongLine->productVariant, 'productVariant')->create([
        'quantity' => '1.000000',
    ]);
    DB::table('inventory_returns')->where('id', $wrongReturn->getKey())->update([
        'status' => InventoryReturnStatus::Posted->value,
        'ready_at' => now()->subMinute(),
        'posted_at' => now(),
    ]);
    DB::table('inventory_return_lines')->where('id', $wrongLine->getKey())->update([
        'posted_base_quantity' => '1.000000',
        'posted_inventory_movement_id' => $wrongMovement->getKey(),
    ]);
    $supplierReturn = InventoryReturn::factory()->supplier()->create();
    $supplierLine = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $supplierReturn->getKey(),
    ]);
    $supplierMovement = InventoryMovement::factory()->return()->for($supplierLine->productVariant, 'productVariant')->create([
        'quantity' => '1.000000',
    ]);
    DB::table('inventory_movements')->where('id', $supplierMovement->getKey())->update([
        'movement_type' => MovementType::Return->value,
        'quantity' => '1.000000',
        'source_type' => 'inventory_return',
        'source_id' => $supplierReturn->getKey(),
        'source_line_type' => 'inventory_return_line',
        'source_line_id' => $supplierLine->getKey(),
    ]);
    DB::table('inventory_returns')->where('id', $supplierReturn->getKey())->update([
        'status' => InventoryReturnStatus::Posted->value,
        'ready_at' => now()->subMinute(),
        'posted_at' => now(),
    ]);
    DB::table('inventory_return_lines')->where('id', $supplierLine->getKey())->update([
        'posted_base_quantity' => '1.000000',
        'posted_inventory_movement_id' => $supplierMovement->getKey(),
    ]);
    $movementVariant = ProductVariant::factory()->create();
    $negativeSnapshot = InventoryMovement::factory()->sale()->for($movementVariant, 'productVariant')->create([
        'quantity' => '-2.000000',
    ]);
    DB::table('inventory_movements')->where('id', $negativeSnapshot->getKey())->update([
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $movementVariant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity_delta' => '-2.000000',
    ]);

    $errors = deepReconErrors();

    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA ignore_check_constraints = OFF');
    } else {
        Schema::enableForeignKeyConstraints();
    }

    expect(deepReconContains($errors, 'has no lot identity'))->toBeTrue()
        ->and(deepReconContains($errors, 'contains a negative quantity'))->toBeTrue()
        ->and(deepReconContains($errors, 'references a missing lot'))->toBeTrue()
        ->and(deepReconContains($errors, 'has no return header'))->toBeTrue()
        ->and(deepReconContains($errors, 'Unposted inventory return line'))->toBeTrue()
        ->and(deepReconContains($errors, 'does not point to its canonical Return movement'))->toBeTrue()
        ->and(deepReconContains($errors, 'movement sign/quantity is incorrect'))->toBeTrue();
});

it('covers canonical posted customer return movement reconciliation', function (): void {
    $return = InventoryReturn::factory()->customer()->create();
    $line = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $return->getKey(),
    ]);
    $movement = InventoryMovement::factory()->return()->for($line->productVariant, 'productVariant')->create([
        'quantity' => '1.000000',
    ]);
    DB::table('inventory_movements')->where('id', $movement->getKey())->update([
        'movement_type' => MovementType::Return->value,
        'quantity' => '1.000000',
        'source_type' => 'inventory_return',
        'source_id' => $return->getKey(),
        'source_line_type' => 'inventory_return_line',
        'source_line_id' => $line->getKey(),
    ]);
    DB::table('inventory_returns')->where('id', $return->getKey())->update([
        'status' => InventoryReturnStatus::Posted->value,
        'ready_at' => now()->subMinute(),
        'posted_at' => now(),
    ]);
    DB::table('inventory_return_lines')->where('id', $line->getKey())->update([
        'posted_base_quantity' => '1.000000',
        'posted_inventory_movement_id' => $movement->getKey(),
    ]);

    expect(deepReconContains(deepReconErrors(), 'movement sign/quantity is incorrect'))->toBeFalse();
});
