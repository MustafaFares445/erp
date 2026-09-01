<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryPostingCommand;
use App\Enums\InventoryPostingBalanceMode;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('posts aggregate lot-balance and serialized custody mutations atomically', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Saleable,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    app(InventoryPostingService::class)->post(new InventoryPostingCommand(
        productVariantId: (int) $variant->getKey(),
        warehouseId: (int) $warehouse->getKey(),
        onHandBaseQuantityDelta: '-1.000000',
        reservedBaseQuantityDelta: '0',
        damagedBaseQuantityDelta: '0',
        movementType: MovementType::Adjustment,
        movementBaseQuantityDelta: '-1.000000',
        sourceType: 'phase6-test',
        sourceId: 1,
        actorId: null,
        serializedInventoryUnitId: (int) $unit->getKey(),
        idempotencyKey: 'phase6-test:1',
        balanceMode: InventoryPostingBalanceMode::RequireExisting,
        inventoryLotId: (int) $lot->getKey(),
        transactionQuantity: '1.000000',
        transactionUnitId: (int) $variant->unit_id,
        conversionFactorSnapshot: '1.000000',
        baseQuantityDelta: '-1.000000',
        lotOnHandBaseQuantityDelta: '-1.000000',
        serializedTargetStatus: SerializedInventoryUnitStatus::AdjustedOut,
        serializedWarehouseSpecified: true,
        serializedTargetCustodyType: SerializedCustodyType::Unknown,
    ));

    expect($stock->refresh()->on_hand_quantity)->toBe('4.000000')
        ->and(lotBalanceQuantity($lot, $warehouse))->toBe('4.000000')
        ->and($lot->refresh()->warehouse_id)->toBeNull()
        ->and($lot->on_hand_quantity)->toBe(0)
        ->and($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::AdjustedOut)
        ->and($unit->warehouse_id)->toBeNull();
});

it('rolls back aggregate stock when a lot-balance mutation becomes invalid', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '1.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);

    expect(fn () => app(InventoryPostingService::class)->post(new InventoryPostingCommand(
        productVariantId: (int) $variant->getKey(),
        warehouseId: (int) $warehouse->getKey(),
        onHandBaseQuantityDelta: '-2.000000',
        reservedBaseQuantityDelta: '0',
        damagedBaseQuantityDelta: '0',
        movementType: MovementType::Adjustment,
        movementBaseQuantityDelta: '-2.000000',
        sourceType: 'phase6-test',
        sourceId: 2,
        actorId: null,
        balanceMode: InventoryPostingBalanceMode::RequireExisting,
        inventoryLotId: (int) $lot->getKey(),
        transactionQuantity: '2.000000',
        transactionUnitId: (int) $variant->unit_id,
        conversionFactorSnapshot: '1.000000',
        baseQuantityDelta: '-2.000000',
        lotOnHandBaseQuantityDelta: '-2.000000',
    )))->toThrow(DomainException::class);

    expect($stock->refresh()->on_hand_quantity)->toBe('5.000000')
        ->and(lotBalanceQuantity($lot, $warehouse))->toBe('1.000000');
});

it('moves saleable stock into quarantine without changing physical on-hand', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);

    app(InventoryPostingService::class)->post(new InventoryPostingCommand(
        productVariantId: (int) $variant->getKey(),
        warehouseId: (int) $warehouse->getKey(),
        onHandBaseQuantityDelta: '0.000000',
        reservedBaseQuantityDelta: '0.000000',
        damagedBaseQuantityDelta: '0.000000',
        movementType: MovementType::Adjustment,
        movementBaseQuantityDelta: '-2.000000',
        sourceType: 'phase6-condition-test',
        sourceId: 10,
        actorId: null,
        idempotencyKey: 'phase6-condition-test:quarantine',
        balanceMode: InventoryPostingBalanceMode::RequireExisting,
        transactionQuantity: '2.000000',
        transactionUnitId: (int) $variant->unit_id,
        conversionFactorSnapshot: '1.000000',
        baseQuantityDelta: '-2.000000',
        conditionFrom: StockCondition::Saleable,
        conditionTo: StockCondition::Quarantine,
        conditionTransferBaseQuantity: '2.000000',
    ));

    $saleable = InventoryConditionBalance::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->where('stock_condition', StockCondition::Saleable->value)
        ->sole();
    $quarantine = InventoryConditionBalance::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->where('stock_condition', StockCondition::Quarantine->value)
        ->sole();

    expect($stock->refresh()->on_hand_quantity)->toBe('5.000000')
        ->and($stock->available_quantity)->toBe('3.000000')
        ->and($saleable->on_hand_base_quantity)->toBe('3.000000')
        ->and($quarantine->on_hand_base_quantity)->toBe('2.000000');

    $movement = InventoryMovement::query()
        ->where('idempotency_key', 'phase6-condition-test:quarantine')
        ->sole();

    expect($movement->stock_condition_from)->toBe(StockCondition::Saleable)
        ->and($movement->stock_condition_to)->toBe(StockCondition::Quarantine)
        ->and($movement->condition_from_on_hand_before)->toBe('5.000000')
        ->and($movement->condition_from_on_hand_after)->toBe('3.000000');
});

function lotBalanceQuantity(InventoryLot $lot, Warehouse $warehouse): string
{
    return (string) InventoryLotBalance::query()
        ->where('inventory_lot_id', $lot->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->where('stock_condition', StockCondition::Saleable->value)
        ->value('on_hand_base_quantity');
}
