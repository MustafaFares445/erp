<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryPostingCommand;
use App\Enums\MovementType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function postingStateInvoke(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(InventoryPostingService::class, $method)
        ->invoke(app(InventoryPostingService::class), ...$arguments);
}

/** @param array<string,mixed> $overrides */
function postingStateCommand(array $overrides = []): InventoryPostingCommand
{
    return new InventoryPostingCommand(...[
        'productVariantId' => 1,
        'warehouseId' => 1,
        'onHandBaseQuantityDelta' => '0.000000',
        'reservedBaseQuantityDelta' => '0.000000',
        'damagedBaseQuantityDelta' => '0.000000',
        'movementType' => MovementType::Adjustment,
        'movementBaseQuantityDelta' => '0.000000',
        'sourceType' => 'coverage-state',
        'sourceId' => 1,
        'actorId' => null,
        ...$overrides,
    ]);
}

function postingStateBalance(StockCondition $condition, string $onHand, string $reserved = '0.000000'): InventoryConditionBalance
{
    $balance = new InventoryConditionBalance;
    $balance->forceFill([
        'stock_condition' => $condition,
        'on_hand_base_quantity' => $onHand,
        'reserved_base_quantity' => $reserved,
    ]);

    return $balance;
}
it('returns an already posted idempotent result before requiring new posting state', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '1.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '1.000000',
    ]);

    InventoryMovement::query()->forceCreate([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => MovementType::Receipt,
        'quantity' => '1.000000',
        'source_type' => 'coverage-existing',
        'source_id' => 17,
        'idempotency_key' => 'coverage-existing-key',
        'created_by' => $actor->getKey(),
        'status' => 'confirmed',
    ]);

    $command = postingStateCommand([
        'productVariantId' => (int) $variant->getKey(),
        'warehouseId' => (int) $warehouse->getKey(),
        'movementType' => MovementType::Receipt,
        'movementBaseQuantityDelta' => '1.000000',
        'sourceType' => 'coverage-existing',
        'sourceId' => 17,
        'actorId' => (int) $actor->getKey(),
        'idempotencyKey' => 'coverage-existing-key',
    ]);

    $result = postingStateInvoke('postNewCommand', $command, [], [], [], [], []);

    expect($result->alreadyPosted)->toBeTrue()
        ->and($result->movement->idempotency_key)->toBe('coverage-existing-key');
});
it('covers lot and serialized lookup guards', function (): void {
    expect(fn (): mixed => postingStateInvoke('lotsForUpdate', [
        postingStateCommand(['inventoryLotId' => 999999]),
    ]))->toThrow(ModelNotFoundException::class);

    $canonical = InventoryLot::factory()->canonical()->create();
    $alias = InventoryLot::factory()->canonical()->create([
        'product_variant_id' => $canonical->product_variant_id,
        'canonical_inventory_lot_id' => $canonical->getKey(),
    ]);

    expect(fn (): mixed => postingStateInvoke('lotsForUpdate', [
        postingStateCommand(['inventoryLotId' => (int) $alias->getKey()]),
    ]))->toThrow(DomainException::class, 'canonical lot identity');

    $lots = postingStateInvoke('lotsForUpdate', [
        postingStateCommand([
            'serializedInventoryLotSpecified' => true,
            'serializedTargetInventoryLotId' => (int) $canonical->getKey(),
        ]),
    ]);
    expect($lots)->toHaveKey($canonical->getKey());

    expect(fn (): mixed => postingStateInvoke('serializedUnitsForUpdate', [
        postingStateCommand(['serializedInventoryUnitId' => 999999]),
    ]))->toThrow(ModelNotFoundException::class);
});
it('covers condition balance reservation and reconciliation guards', function (): void {
    expect(fn (): mixed => postingStateInvoke('assertConditionQuantities', StockCondition::Quarantine, '1.000000', '1.000000'))
        ->toThrow(DomainException::class, 'Only saleable stock')
        ->and(fn (): mixed => postingStateInvoke('assertConditionQuantities', StockCondition::Saleable, '1.000000', '2.000000'))
        ->toThrow(DomainException::class, 'cannot exceed saleable on-hand');

    $stock = new InventoryStock;
    $stock->forceFill([
        'product_variant_id' => 11,
        'warehouse_id' => 22,
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '10.000000',
    ]);
    $balances = [
        '11:22:saleable' => postingStateBalance(StockCondition::Saleable, '8.000000'),
        '11:22:quarantine' => postingStateBalance(StockCondition::Quarantine, '1.000000'),
        '11:22:damaged' => postingStateBalance(StockCondition::Damaged, '0.000000'),
    ];

    expect(fn (): mixed => postingStateInvoke('reconcileStockCompatibility', $stock, $balances))
        ->toThrow(DomainException::class, 'do not reconcile');

    $snapshot = postingStateInvoke(
        'conditionSnapshot',
        postingStateCommand([
            'productVariantId' => 11,
            'warehouseId' => 22,
            'conditionFrom' => StockCondition::Saleable,
            'conditionTo' => StockCondition::Disposed,
        ]),
        $balances,
    );

    expect($snapshot['to_on_hand'])->toBeNull()
        ->and($snapshot['to_reserved'])->toBeNull();
});
it('covers condition delta defensive guards', function (): void {
    $command = postingStateCommand([
        'conditionTransferBaseQuantity' => '1.000000',
        'conditionTo' => StockCondition::Saleable,
    ]);

    expect(fn (): mixed => postingStateInvoke('applyConditionDeltas', $command, []))
        ->toThrow(DomainException::class, 'materialized condition to transfer from');

    $lotCommand = postingStateCommand([
        'inventoryLotId' => 1,
        'conditionTransferBaseQuantity' => '1.000000',
        'conditionTo' => StockCondition::Saleable,
    ]);

    expect(fn (): mixed => postingStateInvoke('applyLotConditionDeltas', $lotCommand, []))
        ->toThrow(DomainException::class, 'materialized condition to transfer from');
});
it('covers lot variant and serialized transition state guards', function (): void {
    $variantA = ProductVariant::factory()->create();
    $variantB = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $lot = InventoryLot::factory()->canonical()->create([
        'product_variant_id' => $variantB->getKey(),
    ]);

    expect(fn (): mixed => postingStateInvoke(
        'lotConditionBalancesForUpdate',
        [postingStateCommand([
            'productVariantId' => (int) $variantA->getKey(),
            'warehouseId' => (int) $warehouse->getKey(),
            'inventoryLotId' => (int) $lot->getKey(),
        ])],
        [(int) $lot->getKey() => $lot],
    ))->toThrow(DomainException::class, 'does not belong to the posting variant');

    expect(postingStateInvoke('applySerializedTransition', postingStateCommand(), [], []))->toBeNull();

    $wrongUnit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variantB->getKey(),
    ]);
    $wrongUnitCommand = postingStateCommand([
        'productVariantId' => (int) $variantA->getKey(),
        'serializedInventoryUnitId' => (int) $wrongUnit->getKey(),
        'serializedTargetStatus' => SerializedInventoryUnitStatus::Available,
    ]);

    expect(fn (): mixed => postingStateInvoke(
        'applySerializedTransition',
        $wrongUnitCommand,
        [(int) $wrongUnit->getKey() => $wrongUnit],
        [],
    ))->toThrow(DomainException::class, 'does not belong to the posting variant');

    $matchingUnit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variantA->getKey(),
    ]);
    $targetLotCommand = postingStateCommand([
        'productVariantId' => (int) $variantA->getKey(),
        'inventoryLotId' => (int) $lot->getKey(),
        'serializedInventoryUnitId' => (int) $matchingUnit->getKey(),
        'serializedInventoryLotSpecified' => true,
        'serializedTargetInventoryLotId' => (int) $lot->getKey(),
    ]);

    expect(fn (): mixed => postingStateInvoke(
        'applySerializedTransition',
        $targetLotCommand,
        [(int) $matchingUnit->getKey() => $matchingUnit],
        [(int) $lot->getKey() => $lot],
    ))->toThrow(DomainException::class, 'target lot does not belong');
});
it('covers reversal reference missing mismatch and direction guards', function (): void {
    expect(fn (): mixed => postingStateInvoke(
        'assertReversalReference',
        postingStateCommand(['reversalOfMovementId' => 999999]),
    ))->toThrow(DomainException::class, 'existing original movement');

    $variantA = ProductVariant::factory()->create();
    $variantB = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $wrongVariant = InventoryMovement::factory()->create([
        'product_variant_id' => $variantB->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'quantity' => '1.000000',
        'base_quantity_delta' => '1.000000',
    ]);

    expect(fn (): mixed => postingStateInvoke(
        'assertReversalReference',
        postingStateCommand([
            'productVariantId' => (int) $variantA->getKey(),
            'warehouseId' => (int) $warehouse->getKey(),
            'reversalOfMovementId' => (int) $wrongVariant->getKey(),
            'movementBaseQuantityDelta' => '-1.000000',
            'baseQuantityDelta' => '-1.000000',
        ]),
    ))->toThrow(DomainException::class, 'same variant and warehouse');

    $same = InventoryMovement::factory()->create([
        'product_variant_id' => $variantA->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'quantity' => '1.000000',
        'base_quantity_delta' => '1.000000',
    ]);

    expect(fn (): mixed => postingStateInvoke(
        'assertReversalReference',
        postingStateCommand([
            'productVariantId' => (int) $variantA->getKey(),
            'warehouseId' => (int) $warehouse->getKey(),
            'reversalOfMovementId' => (int) $same->getKey(),
            'movementBaseQuantityDelta' => '1.000000',
            'baseQuantityDelta' => '1.000000',
        ]),
    ))->toThrow(DomainException::class, 'opposite quantity direction');
});
