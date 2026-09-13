<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\PurchaseInboundStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps allocation provenance stock and aggregate purchasing state correct across a split warehouse receipt lifecycle', function (): void {
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();

    $manager = User::factory()->create();
    $manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($manager);

    $allocator = User::factory()->create();
    $allocator->givePermissionTo(InventoryPermission::InboundAllocate->value);

    $variant = ProductVariant::factory()->create();
    $unit = $variant->unit()->firstOrFail();
    $warehouseA = Warehouse::factory()->create(['is_active' => true]);
    $warehouseB = Warehouse::factory()->create(['is_active' => true]);

    foreach ([$warehouseA, $warehouseB] as $warehouse) {
        InventoryStock::factory()->create([
            'warehouse_id' => $warehouse->getKey(),
            'product_variant_id' => $variant->getKey(),
            'on_hand_quantity' => 0,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'available_quantity' => 0,
        ]);
    }

    $order = PurchaseOrder::factory()->sent()->create();
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => '100',
        'unit_cost' => '5.00',
        'line_total' => '500.00',
    ]);

    $snapshot = app(QuantityNormalizer::class)->normalize($variant, (int) $unit->getKey(), '100');
    $line->forceFill([
        'transaction_quantity' => $snapshot->transactionQuantity,
        'transaction_unit_id' => $snapshot->transactionUnitId,
        'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
        'base_quantity' => $snapshot->baseQuantity,
        'received_base_quantity' => '0.000000',
    ])->save();

    $inboundService = app(PurchaseInboundService::class);
    $receiving = app(PurchaseOrderReceivingService::class);
    $operations = app(InventoryOperationService::class);
    $inbound = $inboundService->ensureForAccepted($order);
    $inboundLine = $inbound->lines()->where('purchase_order_line_id', $line->getKey())->firstOrFail();

    $allocationA = $inboundService->allocate($allocator, $inboundLine, $warehouseA, '60');
    $allocationB = $inboundService->allocate($allocator, $inboundLine, $warehouseB, '40');

    expect($inbound->fresh()->status)->toBe(PurchaseInboundStatus::AwaitingReceipt)
        ->and($receiving->availableBaseQuantityForAllocation($allocationA))->toBe('60.000000')
        ->and($receiving->availableBaseQuantityForAllocation($allocationB))->toBe('40.000000');

    foreach ([
        [$allocationA, '20'],
        [$allocationB, '15'],
    ] as [$allocation, $quantity]) {
        $receipt = $receiving->initiate($manager, $order->refresh(), [[
            'purchase_inbound_allocation_id' => (int) $allocation->getKey(),
            'quantity' => $quantity,
        ]]);

        expect($receipt->stage)->toBe(OperationStage::Draft)
            ->and($receipt->destination_warehouse_id)->toBe($allocation->warehouse_id)
            ->and($receipt->lines->sole()->purchase_inbound_allocation_id)->toBe($allocation->getKey());

        $operations->markReady($receipt, $manager);
        $operations->complete($receipt->refresh(), $manager);
    }

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and($line->fresh()->received_base_quantity)->toBe('35.000000')
        ->and($inbound->fresh()->status)->toBe(PurchaseInboundStatus::PartiallyReceived)
        ->and($allocationA->fresh()->receivedBaseQuantity())->toBe('20.000000')
        ->and($allocationB->fresh()->receivedBaseQuantity())->toBe('15.000000')
        ->and($receiving->availableBaseQuantityForAllocation($allocationA->fresh()))->toBe('40.000000')
        ->and($receiving->availableBaseQuantityForAllocation($allocationB->fresh()))->toBe('25.000000');

    foreach ([
        [$allocationA, '40'],
        [$allocationB, '25'],
    ] as [$allocation, $quantity]) {
        $receipt = $receiving->initiate($manager, $order->refresh(), [[
            'purchase_inbound_allocation_id' => (int) $allocation->getKey(),
            'quantity' => $quantity,
        ]]);
        $operations->markReady($receipt, $manager);
        $operations->complete($receipt->refresh(), $manager);
    }

    $allocationLines = InventoryOperationLine::query()
        ->whereIn('purchase_inbound_allocation_id', [$allocationA->getKey(), $allocationB->getKey()])
        ->where('purchase_order_line_id', $line->getKey())
        ->get();

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($line->fresh()->received_base_quantity)->toBe('100.000000')
        ->and($inbound->fresh()->status)->toBe(PurchaseInboundStatus::Received)
        ->and($inbound->fresh()->completed_at)->not->toBeNull()
        ->and($allocationA->fresh()->receivedBaseQuantity())->toBe('60.000000')
        ->and($allocationA->fresh()->remainingBaseQuantity())->toBe('0.000000')
        ->and($allocationB->fresh()->receivedBaseQuantity())->toBe('40.000000')
        ->and($allocationB->fresh()->remainingBaseQuantity())->toBe('0.000000')
        ->and($receiving->availableBaseQuantityForAllocation($allocationA->fresh()))->toBe('0.000000')
        ->and($receiving->availableBaseQuantityForAllocation($allocationB->fresh()))->toBe('0.000000')
        ->and($allocationLines)->toHaveCount(4)
        ->and($allocationLines->whereNull('purchase_inbound_allocation_id'))->toBeEmpty()
        ->and((float) InventoryStock::query()
            ->where('warehouse_id', $warehouseA->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->value('on_hand_quantity'))->toBe(60.0)
        ->and((float) InventoryStock::query()
            ->where('warehouse_id', $warehouseB->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->value('on_hand_quantity'))->toBe(40.0);
});
