<?php

declare(strict_types=1);

use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\PurchaseOrderWarehouseAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns no availability rows for a purchase order without lines', function (): void {
    $order = PurchaseOrder::factory()->create();

    expect(app(PurchaseOrderWarehouseAvailabilityService::class)->rows($order))->toBe([]);
});

it('shows read-only inventory availability for every ordered variant across active warehouses', function (): void {
    $variant = ProductVariant::factory()->create(['sku' => 'PO-MATRIX-001']);
    $order = PurchaseOrder::factory()->create();
    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 5,
        'unit_cost' => '10.00',
        'line_total' => '50.00',
    ]);

    $stockedWarehouse = Warehouse::factory()->create(['name' => 'Main Warehouse']);
    $incomingWarehouse = Warehouse::factory()->create(['name' => 'Overflow Warehouse']);
    Warehouse::factory()->create(['name' => 'Inactive Warehouse', 'is_active' => false]);

    InventoryStock::factory()->create([
        'warehouse_id' => $stockedWarehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '2.000000',
        'damaged_quantity' => '1.000000',
        'available_quantity' => '7.000000',
    ]);
    WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $stockedWarehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'min_quantity' => '5.000000',
        'max_quantity' => '20.000000',
        'is_active' => true,
    ]);

    $transfer = InventoryOperation::factory()->internalTransfer()->inTransit()->create([
        'destination_warehouse_id' => $incomingWarehouse->getKey(),
    ]);
    InventoryOperationLine::factory()->for($transfer, 'operation')->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '4.000000',
        'dispatched_base_quantity' => '4.000000',
        'received_base_quantity' => '1.000000',
    ]);

    $rows = app(PurchaseOrderWarehouseAvailabilityService::class)->rows($order);
    $main = collect($rows)->firstWhere('warehouse', 'Main Warehouse');
    $overflow = collect($rows)->firstWhere('warehouse', 'Overflow Warehouse');

    expect($rows)->toHaveCount(2)
        ->and($main)->not->toBeNull()
        ->and($main['sku'])->toBe('PO-MATRIX-001')
        ->and($main['on_hand'])->toBe(10.0)
        ->and($main['reserved'])->toBe(2.0)
        ->and($main['saleable_available'])->toBe(7.0)
        ->and($main['in_transit'])->toBe(0.0)
        ->and($main['projected'])->toBe(7.0)
        ->and($overflow)->not->toBeNull()
        ->and($overflow['on_hand'])->toBe(0.0)
        ->and($overflow['reserved'])->toBe(0.0)
        ->and($overflow['saleable_available'])->toBe(0.0)
        ->and($overflow['in_transit'])->toBe(3.0)
        ->and($overflow['projected'])->toBe(3.0);
});
