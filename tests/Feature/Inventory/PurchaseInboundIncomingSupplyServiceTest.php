<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\ReplenishmentCoverageSourceType;
use App\Enums\ReplenishmentCoverageStatus;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentCoverage;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Inventory\ReplenishmentProjectionService;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Supply\PurchaseReplenishmentCoverageService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses PO totals only as a deterministic fallback for one historical allocation', function (): void {
    (new InventoryPermissionSeeder)->run();

    $allocator = User::factory()->create();
    $allocator->givePermissionTo(InventoryPermission::InboundAllocate->value);

    $variant = ProductVariant::factory()->create();
    $unit = $variant->unit()->firstOrFail();
    $warehouse = Warehouse::factory()->create();

    InventoryStock::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'on_hand_quantity' => 0,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 0,
    ]);

    $policy = WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'min_quantity' => 5,
        'max_quantity' => 10,
        'is_active' => true,
    ]);

    $order = PurchaseOrder::factory()->accepted()->create();
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => '10',
        'unit_cost' => '5.00',
        'line_total' => '50.00',
    ]);
    $snapshot = app(QuantityNormalizer::class)->normalize($variant, (int) $unit->getKey(), '10');
    $line->forceFill([
        'transaction_quantity' => $snapshot->transactionQuantity,
        'transaction_unit_id' => $snapshot->transactionUnitId,
        'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
        'base_quantity' => $snapshot->baseQuantity,
        'received_base_quantity' => '0.000000',
    ])->save();

    $inbound = app(PurchaseInboundService::class)->allocateAllTo($allocator, $order, $warehouse);
    $inboundLine = $inbound->lines()->where('purchase_order_line_id', $line->getKey())->firstOrFail();
    $allocation = $inboundLine->allocations()->sole();

    // Simulate a legacy row whose warehouse is deterministic but whose allocation
    // quantity predates Phase 4. The PO line remains the only safe quantity source.
    $allocation->forceFill(['allocated_base_quantity' => null])->save();
    $line->forceFill([
        'received_base_quantity' => '4.000000',
        'quantity_received' => '4.000000',
    ])->save();

    app(PurchaseReplenishmentCoverageService::class)->syncForInboundLine($inboundLine->refresh());

    $coverage = ReplenishmentCoverage::query()
        ->where('source_type', ReplenishmentCoverageSourceType::PurchaseOrderLine->value)
        ->where('source_id', $line->getKey())
        ->where('status', ReplenishmentCoverageStatus::Active->value)
        ->sole();

    expect((float) $coverage->covered_base_quantity)->toBe(6.0)
        ->and(app(ReplenishmentProjectionService::class)->project($policy)->incomingPurchase)->toBe(6.0);
});
