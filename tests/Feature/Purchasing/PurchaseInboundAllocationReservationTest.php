<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Purchasing\Exceptions\InvalidPurchaseInboundAllocation;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not let allocation edits invalidate an active purchase receipt reservation', function (): void {
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();

    $manager = User::factory()->create();
    $manager->assignRole(DashboardRole::PurchasingManager->value);

    $allocator = User::factory()->create();
    $allocator->givePermissionTo(InventoryPermission::InboundAllocate->value);

    $variant = ProductVariant::factory()->create();
    $unit = $variant->unit()->firstOrFail();
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
    $inbound = $inboundService->ensureForAccepted($order);
    $inboundLine = $inbound->lines()->where('purchase_order_line_id', $line->getKey())->firstOrFail();
    $warehouseA = Warehouse::factory()->create(['is_active' => true]);
    $warehouseB = Warehouse::factory()->create(['is_active' => true]);
    $allocation = $inboundService->allocate($allocator, $inboundLine, $warehouseA, '60');
    $receivingService = app(PurchaseOrderReceivingService::class);

    expect($receivingService->availableBaseQuantityForAllocation($allocation))->toBe('60.000000');

    $receivingService->initiate($manager, $order, [[
        'purchase_inbound_allocation_id' => $allocation->getKey(),
        'quantity' => '30',
    ]]);

    expect($receivingService->availableBaseQuantityForAllocation($allocation))->toBe('30.000000');

    expect(fn () => $inboundService->updateAllocation($allocator, $inboundLine, $allocation, $warehouseA, '29'))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'received or reserved');

    expect(fn () => $inboundService->updateAllocation($allocator, $inboundLine, $allocation, $warehouseB, '60'))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'cannot be moved');

    expect(fn () => $inboundService->removeAllocation($allocator, $inboundLine, $allocation))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'cannot be deleted');
});
