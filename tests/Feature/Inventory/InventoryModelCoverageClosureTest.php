<?php

declare(strict_types=1);

use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\InventoryReservationAllocation;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
use App\Observers\InventoryMovementObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers stock condition semantics', function (): void {
    expect(StockCondition::Saleable->isMaterialized())->toBeTrue()
        ->and(StockCondition::Quarantine->isMaterialized())->toBeTrue()
        ->and(StockCondition::Damaged->isMaterialized())->toBeTrue()
        ->and(StockCondition::Disposed->isMaterialized())->toBeFalse()
        ->and(StockCondition::Saleable->allowsReservation())->toBeTrue()
        ->and(StockCondition::Quarantine->allowsReservation())->toBeFalse()
        ->and(StockCondition::Damaged->allowsReservation())->toBeFalse()
        ->and(StockCondition::Disposed->allowsReservation())->toBeFalse();
});

it('covers aggregate and lot condition balance relations, casts, and availability branches', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $lot = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create();

    $aggregate = new InventoryConditionBalance([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '8.000000',
        'reserved_base_quantity' => '3.000000',
    ]);

    expect($aggregate->productVariant())->not->toBeNull()
        ->and($aggregate->warehouse())->not->toBeNull()
        ->and($aggregate->availableBaseQuantity())->toBe('5.000000');

    $aggregate->stock_condition = StockCondition::Damaged;
    expect($aggregate->availableBaseQuantity())->toBe('0.000000');

    $lotBalance = new InventoryLotBalance;
    $lotBalance->forceFill([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '9.000000',
        'reserved_base_quantity' => '4.000000',
    ]);

    expect($lotBalance->lot())->not->toBeNull()
        ->and($lotBalance->warehouse())->not->toBeNull()
        ->and($lotBalance->availableBaseQuantity())->toBe('5.000000');

    $lotBalance->stock_condition = StockCondition::Quarantine;
    expect($lotBalance->availableBaseQuantity())->toBe('0.000000');
});

it('covers inventory reservation allocation relationships and decimal casting', function (): void {
    $reservation = InventoryReservation::factory()->create();
    $lot = InventoryLot::factory()->canonical()->for($reservation->productVariant, 'productVariant')->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $reservation->product_variant_id,
        'warehouse_id' => $reservation->warehouse_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    $allocation = InventoryReservationAllocation::query()->create([
        'inventory_reservation_id' => $reservation->getKey(),
        'inventory_lot_id' => $lot->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'base_quantity' => '1.250000',
    ]);

    expect($allocation->base_quantity)->toBe('1.250000')
        ->and($allocation->reservation()->first()?->is($reservation))->toBeTrue()
        ->and($allocation->lot()->first()?->is($lot))->toBeTrue()
        ->and($allocation->serializedUnit()->first()?->is($unit))->toBeTrue();
});

it('covers serialized unit lot, movement, and receipt movement relations', function (): void {
    $unit = SerializedInventoryUnit::factory()->create();

    expect($unit->productVariant())->not->toBeNull()
        ->and($unit->warehouse())->not->toBeNull()
        ->and($unit->lot())->not->toBeNull()
        ->and($unit->movements())->not->toBeNull()
        ->and($unit->receiptMovement())->not->toBeNull();
});

it('enforces inventory movement immutability through both observer hooks', function (): void {
    $observer = new InventoryMovementObserver;

    expect(fn () => $observer->updating())
        ->toThrow(LogicException::class, 'Inventory movements are immutable.')
        ->and(fn () => $observer->deleting())
        ->toThrow(LogicException::class, 'Inventory movements are immutable.');
});

it('covers lot normalization, canonical relations, quantity helpers, and identity lock', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    expect(InventoryLot::normalizeLotNumber(null))->toBeNull()
        ->and(InventoryLot::normalizeLotNumber('   '))->toBeNull()
        ->and(InventoryLot::normalizeLotNumber(' lot   abc '))->toBe('LOT ABC');

    $lot = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create([
        'lot_number' => ' lot  one ',
        'expires_at' => null,
    ]);

    expect($lot->normalized_lot_number)->toBe('LOT ONE')
        ->and($lot->productVariant())->not->toBeNull()
        ->and($lot->warehouse())->not->toBeNull()
        ->and($lot->conditionBalances())->not->toBeNull()
        ->and($lot->movements())->not->toBeNull()
        ->and($lot->reservationAllocations())->not->toBeNull()
        ->and($lot->canonicalLot())->not->toBeNull()
        ->and($lot->conditionOnHandQuantity(StockCondition::Saleable, (int) $warehouse->getKey()))->toBe(0.0)
        ->and($lot->conditionReservedQuantity(StockCondition::Saleable, (int) $warehouse->getKey()))->toBe(0.0)
        ->and($lot->availableQuantity((int) $warehouse->getKey()))->toBe(0.0)
        ->and($lot->totalPhysicalQuantity())->toBe(0.0)
        ->and($lot->totalAvailableQuantity())->toBe(0.0)
        ->and($lot->totalConditionOnHandQuantity(StockCondition::Damaged))->toBe(0.0)
        ->and($lot->totalConditionReservedQuantity(StockCondition::Saleable))->toBe(0.0)
        ->and($lot->warehouseCount())->toBe(0)
        ->and($lot->daysRemaining())->toBeNull()
        ->and($lot->expiryState())->toBe('no_expiry');

    $lot->update(['lot_number' => 'lot two']);
    expect($lot->refresh()->normalized_lot_number)->toBe('LOT TWO');

    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '5.000000',
        'reserved_base_quantity' => '2.000000',
    ]);

    expect($lot->refresh()->conditionOnHandQuantity(StockCondition::Saleable, (int) $warehouse->getKey()))->toBe(5.0)
        ->and($lot->conditionReservedQuantity(StockCondition::Saleable, (int) $warehouse->getKey()))->toBe(2.0)
        ->and($lot->availableQuantity((int) $warehouse->getKey()))->toBe(3.0)
        ->and($lot->totalPhysicalQuantity())->toBe(5.0)
        ->and($lot->totalAvailableQuantity())->toBe(3.0)
        ->and($lot->warehouseCount())->toBe(1);

    expect(fn () => $lot->update(['lot_number' => 'locked lot']))
        ->toThrow(DomainException::class, 'immutable after inventory history exists');
});
