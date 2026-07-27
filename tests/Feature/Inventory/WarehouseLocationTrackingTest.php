<?php

declare(strict_types=1);

use App\Data\Inventory\StockDamageData;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryAdjustment;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryDamageService;
use App\Services\Inventory\InventoryReceivingService;
use App\Services\Inventory\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records the chosen bin location on the movement and lot when a receipt is confirmed', function (): void {
    $actor = User::factory()->create();
    $receipt = InventoryReceipt::factory()->create();
    $location = WarehouseLocation::factory()->for($receipt->warehouse, 'warehouse')->create();
    $variant = ProductVariant::factory()->create(['track_expiry' => true]);
    InventoryReceiptItem::factory()->for($receipt, 'receipt')->for($variant, 'productVariant')->create([
        'quantity' => 3,
        'expires_at' => now()->addMonth(),
        'lot_number' => 'LOT-LOC-01',
        'warehouse_location_id' => $location->getKey(),
    ]);

    app(InventoryReceivingService::class)->confirm($receipt, $actor);

    $movement = InventoryMovement::query()->where('movement_type', 'receipt')->firstOrFail();
    $lot = InventoryLot::query()->where('lot_number', 'LOT-LOC-01')->firstOrFail();

    expect($movement->warehouse_location_id)->toBe($location->getKey())
        ->and($lot->warehouse_location_id)->toBe($location->getKey());
});

it('assigns the receipt item location to serialized units created on confirmation', function (): void {
    $actor = User::factory()->create();
    $receipt = InventoryReceipt::factory()->create();
    $location = WarehouseLocation::factory()->for($receipt->warehouse, 'warehouse')->create();
    $variant = ProductVariant::factory()->create(['track_serials' => true]);
    $item = InventoryReceiptItem::factory()->for($receipt, 'receipt')->for($variant, 'productVariant')->create([
        'quantity' => 1,
        'warehouse_location_id' => $location->getKey(),
    ]);
    SerializedInventoryUnit::factory()->for($item, 'receiptItem')->for($variant, 'productVariant')->create([
        'warehouse_id' => null,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);

    app(InventoryReceivingService::class)->confirm($receipt, $actor);

    $unit = SerializedInventoryUnit::query()->where('product_variant_id', $variant->getKey())->firstOrFail();

    expect($unit->warehouse_location_id)->toBe($location->getKey());
});

it('rejects a receipt item location that does not belong to the receiving warehouse', function (): void {
    $actor = User::factory()->create();
    $receipt = InventoryReceipt::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $foreignLocation = WarehouseLocation::factory()->for($otherWarehouse, 'warehouse')->create();
    $variant = ProductVariant::factory()->create();
    InventoryReceiptItem::factory()->for($receipt, 'receipt')->for($variant, 'productVariant')->create([
        'quantity' => 1,
        'warehouse_location_id' => $foreignLocation->getKey(),
    ]);

    expect(fn () => app(InventoryReceivingService::class)->confirm($receipt, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.receipt.errors.location_mismatch'));
});

it('carries the destination location onto the receive movement and serialized unit, leaving the dispatch movement locationless', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $destinationLocation = WarehouseLocation::factory()->for($to, 'warehouse')->create();
    $variant = ProductVariant::factory()->create(['track_serials' => true]);
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '1.000', 'reserved_quantity' => '0.000', 'available_quantity' => '1.000']);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($from, 'warehouse')->create([
        'status' => SerializedInventoryUnitStatus::Available,
    ]);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create([
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'quantity' => '1.000',
        'warehouse_location_id' => $destinationLocation->getKey(),
    ]);

    $actor = User::factory()->create();
    $service = app(StockTransferService::class);
    $service->dispatch($transfer, $actor);
    $service->receive($transfer, $actor);

    $movements = InventoryMovement::query()->where('source_type', 'transfer')->where('source_id', $transfer->getKey())->orderBy('id')->get();

    expect($movements->first()?->warehouse_location_id)->toBeNull()
        ->and($movements->last()?->warehouse_location_id)->toBe($destinationLocation->getKey())
        ->and($unit->fresh()->warehouse_location_id)->toBe($destinationLocation->getKey());
});

it('rejects a transfer destination location that does not belong to the destination warehouse', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $foreignLocation = WarehouseLocation::factory()->for($from, 'warehouse')->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '5.000', 'reserved_quantity' => '0.000', 'available_quantity' => '5.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '2.000',
        'warehouse_location_id' => $foreignLocation->getKey(),
    ]);

    $actor = User::factory()->create();
    $service = app(StockTransferService::class);
    $service->dispatch($transfer, $actor);

    expect(fn () => $service->receive($transfer, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.transfer.errors.location_mismatch'));
});

it('records the adjustment item location on its movement and propagates it to a serialized unit adjusted in', function (): void {
    $warehouse = Warehouse::factory()->create();
    $location = WarehouseLocation::factory()->for($warehouse, 'warehouse')->create();
    $variant = ProductVariant::factory()->create(['track_serials' => true]);
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['on_hand_quantity' => '0.000', 'reserved_quantity' => '0.000', 'available_quantity' => '0.000']);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'warehouse_id' => null,
        'status' => SerializedInventoryUnitStatus::AdjustedOut,
    ]);

    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'new_quantity' => '1.000',
        'warehouse_location_id' => $location->getKey(),
    ]);

    $actor = User::factory()->create();
    app(InventoryAdjustmentService::class)->confirm($adjustment, $actor);

    $movement = InventoryMovement::query()->where('source_type', 'adjustment')->where('source_id', $adjustment->getKey())->firstOrFail();

    expect($movement->warehouse_location_id)->toBe($location->getKey())
        ->and($unit->fresh()->warehouse_location_id)->toBe($location->getKey());
});

it('rejects an adjustment item location that does not belong to the adjustment warehouse', function (): void {
    $warehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $foreignLocation = WarehouseLocation::factory()->for($otherWarehouse, 'warehouse')->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['on_hand_quantity' => '5.000', 'reserved_quantity' => '0.000', 'available_quantity' => '5.000']);

    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'new_quantity' => '8.000',
        'warehouse_location_id' => $foreignLocation->getKey(),
    ]);

    $actor = User::factory()->create();

    expect(fn () => app(InventoryAdjustmentService::class)->confirm($adjustment, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.adjustment.errors.location_mismatch'));
});

it('clears a serialized unit location when it is disposed', function (): void {
    $service = app(InventoryDamageService::class);
    $actor = User::factory()->admin()->create();
    $warehouse = Warehouse::factory()->create();
    $location = WarehouseLocation::factory()->for($warehouse, 'warehouse')->create();
    $stock = InventoryStock::factory()->for($warehouse)->create([
        'on_hand_quantity' => 1,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 1,
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $stock->product_variant_id,
        'warehouse_id' => $stock->warehouse_id,
        'warehouse_location_id' => $location->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
    ]);

    $stock = $service->damage($stock, new StockDamageData(1, 'Device casing damaged', $unit->getKey()), $actor);
    $service->dispose($stock, new StockDamageData(1, 'Device scrapped', $unit->getKey()), $actor);

    expect($unit->fresh()->warehouse_location_id)->toBeNull();
});
