<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\PurchaseInboundStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryMovement;
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
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps split inbound allocations non-physical until Inventory completes receipts', function (): void {
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();
    $buyer = User::factory()->create();
    $buyer->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($buyer);
    $receiver = User::factory()->create();
    $receiver->givePermissionTo([
        InventoryPermission::ReceiptCreate->value,
        InventoryPermission::ReceiptConfirm->value,
    ]);
    $allocator = User::factory()->create();
    $allocator->givePermissionTo(InventoryPermission::InboundAllocate->value);

    $variant = ProductVariant::factory()->create();
    $unit = $variant->unit()->firstOrFail();
    $warehouseA = Warehouse::factory()->create(['is_active' => true]);
    $warehouseB = Warehouse::factory()->create(['is_active' => true]);
    foreach ([$warehouseA, $warehouseB] as $warehouse) {
        InventoryStock::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'on_hand_quantity' => 0,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'available_quantity' => 0,
        ]);
    }

    $po = PurchaseOrder::factory()->sent()->create();
    $line = $po->lines()->create([
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'quantity_ordered' => '10',
        'unit_cost' => '5.00',
        'line_total' => '50.00',
    ]);
    $quantity = app(QuantityNormalizer::class)->normalize($variant, (int) $unit->id, '10');
    $line->forceFill([
        'transaction_quantity' => $quantity->transactionQuantity,
        'transaction_unit_id' => $quantity->transactionUnitId,
        'conversion_factor_snapshot' => $quantity->conversionFactorSnapshot,
        'base_quantity' => $quantity->baseQuantity,
        'received_base_quantity' => '0.000000',
    ])->save();

    $inboundService = app(PurchaseInboundService::class);
    $receiving = app(PurchaseOrderReceivingService::class);
    $operations = app(InventoryOperationService::class);
    $inbound = $inboundService->ensureForAccepted($po);
    $inboundLine = $inbound->lines()->where('purchase_order_line_id', $line->id)->firstOrFail();
    $allocationA = $inboundService->allocate($allocator, $inboundLine, $warehouseA, '6');
    $allocationB = $inboundService->allocate($allocator, $inboundLine, $warehouseB, '4');

    expect($inbound->fresh()->status)->toBe(PurchaseInboundStatus::AwaitingReceipt)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Accepted)
        ->and(InventoryMovement::query()->count())->toBe(0);

    $receiptA = $receiving->initiate($receiver, $po->refresh(), [[
        'purchase_inbound_allocation_id' => (int) $allocationA->id,
        'quantity' => '3',
    ]]);
    expect($receiptA->stage)->toBe(OperationStage::Draft)
        ->and($receiptA->destination_warehouse_id)->toBe($warehouseA->id)
        ->and($receiptA->lines->sole()->purchase_inbound_allocation_id)->toBe($allocationA->id);
    $operations->markReady($receiptA, $receiver);
    expect((float) InventoryStock::query()->where('warehouse_id', $warehouseA->id)->value('on_hand_quantity'))->toBe(0.0)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and($line->fresh()->received_base_quantity)->toBe('0.000000');
    $operations->complete($receiptA->refresh(), $receiver);
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and($inbound->fresh()->status)->toBe(PurchaseInboundStatus::PartiallyReceived)
        ->and($line->fresh()->received_base_quantity)->toBe('3.000000')
        ->and((float) InventoryStock::query()->where('warehouse_id', $warehouseA->id)->value('on_hand_quantity'))->toBe(3.0);

    foreach ([[$allocationA, '3'], [$allocationB, '4']] as [$allocation, $qty]) {
        $receipt = $receiving->initiate($receiver, $po->refresh(), [[
            'purchase_inbound_allocation_id' => (int) $allocation->id,
            'quantity' => $qty,
        ]]);
        $operations->markReady($receipt, $receiver);
        $operations->complete($receipt->refresh(), $receiver);
    }
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($inbound->fresh()->status)->toBe(PurchaseInboundStatus::Received)
        ->and($line->fresh()->received_base_quantity)->toBe('10.000000')
        ->and((float) InventoryStock::query()->where('warehouse_id', $warehouseA->id)->value('on_hand_quantity'))->toBe(6.0)
        ->and((float) InventoryStock::query()->where('warehouse_id', $warehouseB->id)->value('on_hand_quantity'))->toBe(4.0)
        ->and(InventoryOperationLine::query()->where('purchase_order_line_id', $line->id)->count())->toBe(3);
});
