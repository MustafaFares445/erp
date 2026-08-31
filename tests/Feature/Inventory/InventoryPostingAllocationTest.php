<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryPostingCommand;
use App\Enums\InventoryPostingBalanceMode;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('posts aggregate lot and serialized custody mutations atomically', function (): void {
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
        lotOnHandBaseQuantityDelta: '-1.000000',
        serializedTargetStatus: SerializedInventoryUnitStatus::AdjustedOut,
        serializedWarehouseSpecified: true,
        serializedTargetWarehouseId: null,
        serializedTargetCustodyType: SerializedCustodyType::Unknown,
    ));

    expect($stock->refresh()->on_hand_quantity)->toBe('4.000000')
        ->and($lot->refresh()->on_hand_quantity)->toBe('4.000000')
        ->and($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::AdjustedOut)
        ->and($unit->warehouse_id)->toBeNull()
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Unknown);
});

it('rolls back aggregate stock when a lot mutation would become invalid', function (): void {
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
        lotOnHandBaseQuantityDelta: '-2.000000',
    )))->toThrow(DomainException::class);

    expect($stock->refresh()->on_hand_quantity)->toBe('5.000000')
        ->and($lot->refresh()->on_hand_quantity)->toBe('1.000000');
});
