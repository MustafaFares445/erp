<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\ReplenishmentCoverageSourceType;
use App\Enums\ReplenishmentCoverageStatus;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\ReplenishmentCoverage;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\PurchaseReplenishmentCoverageService;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Inventory\ReplenishmentProjectionService;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();

    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);

    $this->allocator = User::factory()->create();
    $this->allocator->givePermissionTo(InventoryPermission::InboundAllocate->value);

    $this->receiving = app(PurchaseOrderReceivingService::class);
    $this->operations = app(InventoryOperationService::class);
});

/**
 * @return array{
 *     order: PurchaseOrder,
 *     line: PurchaseOrderLine,
 *     inbound_line: PurchaseInboundLine,
 *     allocation_a: PurchaseInboundAllocation,
 *     allocation_b: PurchaseInboundAllocation,
 *     warehouse_a: Warehouse,
 *     warehouse_b: Warehouse,
 *     policy_a: WarehouseReplenishmentPolicy,
 *     policy_b: WarehouseReplenishmentPolicy
 * }
 */
function phaseFourReplenishmentContext(User $allocator): array
{
    $variant = ProductVariant::factory()->create();
    $unit = $variant->unit()->firstOrFail();
    $warehouseA = Warehouse::factory()->create();
    $warehouseB = Warehouse::factory()->create();

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

    $policyA = WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $warehouseA->getKey(),
        'product_variant_id' => $variant->getKey(),
        'min_quantity' => 20,
        'max_quantity' => 100,
        'is_active' => true,
    ]);
    $policyB = WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $warehouseB->getKey(),
        'product_variant_id' => $variant->getKey(),
        'min_quantity' => 20,
        'max_quantity' => 100,
        'is_active' => true,
    ]);

    $order = PurchaseOrder::factory()->accepted()->create();
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => '100',
        'unit_cost' => '5.00',
        'line_total' => '500.00',
    ]);
    $snapshot = app(QuantityNormalizer::class)->normalize(
        $variant,
        (int) $unit->getKey(),
        '100',
    );
    $line->forceFill([
        'transaction_quantity' => $snapshot->transactionQuantity,
        'transaction_unit_id' => $snapshot->transactionUnitId,
        'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
        'base_quantity' => $snapshot->baseQuantity,
        'received_base_quantity' => '0.000000',
    ])->save();

    $inboundService = app(PurchaseInboundService::class);
    $inbound = $inboundService->ensureForAccepted($order);
    $inboundLine = $inbound->lines()
        ->where('purchase_order_line_id', $line->getKey())
        ->firstOrFail();
    $allocationA = $inboundService->allocate($allocator, $inboundLine, $warehouseA, '60');
    $allocationB = $inboundService->allocate($allocator, $inboundLine, $warehouseB, '40');

    return [
        'order' => $order->refresh(),
        'line' => $line->refresh(),
        'inbound_line' => $inboundLine->refresh(),
        'allocation_a' => $allocationA,
        'allocation_b' => $allocationB,
        'warehouse_a' => $warehouseA,
        'warehouse_b' => $warehouseB,
        'policy_a' => $policyA,
        'policy_b' => $policyB,
    ];
}

function phaseFourPurchaseCoverage(PurchaseOrderLine $line, Warehouse $warehouse): ReplenishmentCoverage
{
    return ReplenishmentCoverage::query()
        ->where('source_type', ReplenishmentCoverageSourceType::PurchaseOrderLine->value)
        ->where('source_id', $line->getKey())
        ->where('status', ReplenishmentCoverageStatus::Active->value)
        ->whereHas('requirement', static fn ($query) => $query->where('warehouse_id', $warehouse->getKey()))
        ->sole();
}

it('projects and covers each warehouse from only its own purchase allocation', function (): void {
    $context = phaseFourReplenishmentContext($this->allocator);

    $coverageA = phaseFourPurchaseCoverage($context['line'], $context['warehouse_a']);
    $coverageB = phaseFourPurchaseCoverage($context['line'], $context['warehouse_b']);
    $projection = app(ReplenishmentProjectionService::class);

    expect((float) $coverageA->covered_base_quantity)->toBe(60.0)
        ->and((float) $coverageB->covered_base_quantity)->toBe(40.0)
        ->and($projection->project($context['policy_a'])->incomingPurchase)->toBe(60.0)
        ->and($projection->project($context['policy_b'])->incomingPurchase)->toBe(40.0);
});

it('reduces only the receiving warehouse incoming coverage after partial receipts', function (): void {
    $context = phaseFourReplenishmentContext($this->allocator);

    $receiptA = $this->receiving->initiate($this->manager, $context['order'], [[
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
        'quantity' => '20',
    ]]);
    $this->operations->markReady($receiptA, $this->manager);
    $this->operations->complete($receiptA->refresh(), $this->manager);

    expect((float) phaseFourPurchaseCoverage($context['line'], $context['warehouse_a'])->covered_base_quantity)->toBe(40.0)
        ->and((float) phaseFourPurchaseCoverage($context['line'], $context['warehouse_b'])->covered_base_quantity)->toBe(40.0);

    $receiptB = $this->receiving->initiate($this->manager, $context['order']->refresh(), [[
        'purchase_inbound_allocation_id' => $context['allocation_b']->getKey(),
        'quantity' => '15',
    ]]);
    $this->operations->markReady($receiptB, $this->manager);
    $this->operations->complete($receiptB->refresh(), $this->manager);

    $projection = app(ReplenishmentProjectionService::class);

    expect((float) phaseFourPurchaseCoverage($context['line'], $context['warehouse_a'])->covered_base_quantity)->toBe(40.0)
        ->and((float) phaseFourPurchaseCoverage($context['line'], $context['warehouse_b'])->covered_base_quantity)->toBe(25.0)
        ->and($projection->project($context['policy_a'])->incomingPurchase)->toBe(40.0)
        ->and($projection->project($context['policy_b'])->incomingPurchase)->toBe(25.0);
});

it('releases purchase coverage and projects zero incoming when the split PO is fully received', function (): void {
    $context = phaseFourReplenishmentContext($this->allocator);

    foreach ([
        [$context['allocation_a'], '60'],
        [$context['allocation_b'], '40'],
    ] as [$allocation, $quantity]) {
        $receipt = $this->receiving->initiate($this->manager, $context['order']->refresh(), [[
            'purchase_inbound_allocation_id' => $allocation->getKey(),
            'quantity' => $quantity,
        ]]);
        $this->operations->markReady($receipt, $this->manager);
        $this->operations->complete($receipt->refresh(), $this->manager);
    }

    $active = ReplenishmentCoverage::query()
        ->where('source_type', ReplenishmentCoverageSourceType::PurchaseOrderLine->value)
        ->where('source_id', $context['line']->getKey())
        ->where('status', ReplenishmentCoverageStatus::Active->value)
        ->count();
    $projection = app(ReplenishmentProjectionService::class);

    expect($active)->toBe(0)
        ->and($projection->project($context['policy_a'])->incomingPurchase)->toBe(0.0)
        ->and($projection->project($context['policy_b'])->incomingPurchase)->toBe(0.0);
});

it('does not invent incoming distribution for an ambiguous historical split allocation', function (): void {
    $context = phaseFourReplenishmentContext($this->allocator);

    $context['allocation_a']->forceFill(['allocated_base_quantity' => null])->save();
    app(PurchaseReplenishmentCoverageService::class)->syncForInboundLine($context['inbound_line']->refresh());

    $coverageA = ReplenishmentCoverage::query()
        ->where('source_type', ReplenishmentCoverageSourceType::PurchaseOrderLine->value)
        ->where('source_id', $context['line']->getKey())
        ->where('status', ReplenishmentCoverageStatus::Active->value)
        ->whereHas('requirement', static fn ($query) => $query->where('warehouse_id', $context['warehouse_a']->getKey()))
        ->count();

    expect($coverageA)->toBe(0)
        ->and((float) phaseFourPurchaseCoverage($context['line'], $context['warehouse_b'])->covered_base_quantity)->toBe(40.0)
        ->and(app(ReplenishmentProjectionService::class)->project($context['policy_a'])->incomingPurchase)->toBe(0.0);
});
