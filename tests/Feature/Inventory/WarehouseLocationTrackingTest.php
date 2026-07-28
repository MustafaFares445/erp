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
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryDamageService;
use App\Services\Inventory\InventoryReceivingService;
use App\Services\Inventory\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records receipt stock against the selected warehouse only', function (): void {
    $actor = User::factory()->create();
    $receipt = InventoryReceipt::factory()->create();
    $variant = ProductVariant::factory()->create(['track_expiry' => true]);
    InventoryReceiptItem::factory()->for($receipt, 'receipt')->for($variant, 'productVariant')->create([
        'quantity' => 3,
        'expires_at' => now()->addMonth(),
        'lot_number' => 'LOT-WAREHOUSE-01',
    ]);

    app(InventoryReceivingService::class)->confirm($receipt, $actor);

    $movement = InventoryMovement::query()->where('movement_type', 'receipt')->firstOrFail();
    $lot = InventoryLot::query()->where('lot_number', 'LOT-WAREHOUSE-01')->firstOrFail();

    expect($movement->warehouse_id)->toBe($receipt->warehouse_id)
        ->and($lot->warehouse_id)->toBe($receipt->warehouse_id)
        ->and($movement->getAttributes())->not->toHaveKey('warehouse_location_id');
});

it('assigns serialized receipt units to the receiving warehouse', function (): void {
    $actor = User::factory()->create();
    $receipt = InventoryReceipt::factory()->create();
    $variant = ProductVariant::factory()->create(['track_serials' => true]);
    $item = InventoryReceiptItem::factory()->for($receipt, 'receipt')->for($variant, 'productVariant')->create([
        'quantity' => 1,
    ]);
    SerializedInventoryUnit::factory()->for($item, 'receiptItem')->for($variant, 'productVariant')->create([
        'warehouse_id' => null,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);

    app(InventoryReceivingService::class)->confirm($receipt, $actor);

    $unit = SerializedInventoryUnit::query()->where('product_variant_id', $variant->getKey())->firstOrFail();

    expect($unit->warehouse_id)->toBe($receipt->warehouse_id)
        ->and($unit->getAttributes())->not->toHaveKey('warehouse_location_id');
});

it('moves transfers and serialized units between warehouses without locations', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['track_serials' => true]);
    InventoryStock::factory()->for($variant)->for($from)->create([
        'on_hand_quantity' => '1.000',
        'reserved_quantity' => '0.000',
        'available_quantity' => '1.000',
    ]);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($from, 'warehouse')->create([
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create([
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'quantity' => '1.000',
    ]);

    $actor = User::factory()->create();
    $service = app(StockTransferService::class);
    $service->dispatch($transfer, $actor);
    $service->receive($transfer, $actor);

    $movements = InventoryMovement::query()
        ->where('source_type', 'transfer')
        ->where('source_id', $transfer->getKey())
        ->orderBy('id')
        ->get();

    expect($movements->pluck('warehouse_id')->all())->toBe([$from->getKey(), $to->getKey()])
        ->and($unit->fresh()->warehouse_id)->toBe($to->getKey())
        ->and($unit->fresh()->getAttributes())->not->toHaveKey('warehouse_location_id');
});

it('assigns adjusted serialized units to the adjustment warehouse', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['track_serials' => true]);
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '0.000',
        'reserved_quantity' => '0.000',
        'available_quantity' => '0.000',
    ]);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'warehouse_id' => null,
        'status' => SerializedInventoryUnitStatus::AdjustedOut,
    ]);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'new_quantity' => '1.000',
    ]);

    app(InventoryAdjustmentService::class)->confirm($adjustment, User::factory()->create());

    expect($unit->fresh()->warehouse_id)->toBe($warehouse->getKey())
        ->and($unit->fresh()->getAttributes())->not->toHaveKey('warehouse_location_id');
});

it('clears a serialized unit warehouse when it is disposed', function (): void {
    $service = app(InventoryDamageService::class);
    $warehouse = Warehouse::factory()->create();
    $stock = InventoryStock::factory()->for($warehouse)->create([
        'on_hand_quantity' => 1,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 1,
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $stock->product_variant_id,
        'warehouse_id' => $stock->warehouse_id,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    $actor = User::factory()->admin()->create();

    $stock = $service->damage($stock, new StockDamageData(1, 'Device casing damaged', $unit->getKey()), $actor);
    $service->dispose($stock, new StockDamageData(1, 'Device scrapped', $unit->getKey()), $actor);

    expect($unit->fresh()->warehouse_id)->toBeNull();
});
