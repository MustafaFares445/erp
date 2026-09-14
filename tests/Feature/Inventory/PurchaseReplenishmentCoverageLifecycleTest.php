<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
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
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use App\Services\Supply\PurchaseReplenishmentCoverageService;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();

    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);

    $this->allocator = User::factory()->create();
    $this->allocator->givePermissionTo(InventoryPermission::InboundAllocate->value);
    $this->actingAs($this->manager);
});

/** @return array{0: PurchaseOrder, 1: Warehouse, 2: ReplenishmentCoverage} */
function phaseTwoCoveredPurchaseOrder(User $allocator, bool $partiallyReceived = false, float $quantity = 10): array
{
    $variant = ProductVariant::factory()->create();
    $unit = $variant->unit()->firstOrFail();
    $warehouse = Warehouse::factory()->create();

    InventoryStock::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'on_hand_quantity' => 10,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 10,
    ]);

    WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'min_quantity' => 20,
        'max_quantity' => 60,
        'is_active' => true,
    ]);

    $factory = PurchaseOrder::factory();
    $order = $partiallyReceived
        ? $factory->partiallyReceived()->create()
        : $factory->accepted()->create();
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => $quantity,
        'unit_cost' => '5.00',
        'line_total' => $quantity * 5,
    ]);

    $snapshot = app(QuantityNormalizer::class)->normalize(
        $variant,
        (int) $unit->getKey(),
        (string) $quantity,
    );
    $line->forceFill([
        'transaction_quantity' => $snapshot->transactionQuantity,
        'transaction_unit_id' => $snapshot->transactionUnitId,
        'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
        'base_quantity' => $snapshot->baseQuantity,
        'received_base_quantity' => '0.000000',
    ])->save();

    app(PurchaseInboundService::class)->allocateAllTo($allocator, $order, $warehouse);

    $coverage = ReplenishmentCoverage::query()
        ->where('source_type', ReplenishmentCoverageSourceType::PurchaseOrderLine->value)
        ->where('source_id', $line->getKey())
        ->sole();

    return [$order->refresh(), $warehouse, $coverage];
}

it('releases active purchase coverage when an accepted order is cancelled', function (): void {
    [$order, , $coverage] = phaseTwoCoveredPurchaseOrder($this->allocator);

    expect($coverage->status)->toBe(ReplenishmentCoverageStatus::Active);

    app(PurchaseOrderApprovalService::class)->cancel($this->manager, $order, 'No longer required');

    expect($coverage->refresh()->status)->toBe(ReplenishmentCoverageStatus::Released);
});

it('releases active purchase coverage when a partially received order is short closed', function (): void {
    [$order, , $coverage] = phaseTwoCoveredPurchaseOrder($this->allocator, partiallyReceived: true);

    app(PurchaseOrderApprovalService::class)->close($this->manager, $order, 'Supplier cannot deliver the balance');

    expect($coverage->refresh()->status)->toBe(ReplenishmentCoverageStatus::Released);
});

it('reduces purchase coverage to the outstanding quantity after a partial receipt', function (): void {
    [$order, , $coverage] = phaseTwoCoveredPurchaseOrder($this->allocator, quantity: 10);
    $allocation = $order->lines()->firstOrFail()->purchaseInboundLine()->firstOrFail()->allocations()->sole();

    $receipt = app(PurchaseOrderReceivingService::class)->initiate($this->manager, $order, [[
        'purchase_inbound_allocation_id' => $allocation->getKey(),
        'quantity' => 4,
    ]]);

    app(InventoryOperationService::class)->markReady($receipt->refresh(), $this->manager);
    app(InventoryOperationService::class)->complete($receipt->refresh(), $this->manager);

    expect($coverage->refresh()->status)->toBe(ReplenishmentCoverageStatus::Active)
        ->and((float) $coverage->covered_base_quantity)->toBe(6.0);
});

it('releases purchase coverage after the order is fully received', function (): void {
    [$order, , $coverage] = phaseTwoCoveredPurchaseOrder($this->allocator, quantity: 10);

    $receipt = app(PurchaseOrderReceivingService::class)->initiate($this->manager, $order);
    app(InventoryOperationService::class)->markReady($receipt->refresh(), $this->manager);
    app(InventoryOperationService::class)->complete($receipt->refresh(), $this->manager);

    expect($coverage->refresh()->status)->toBe(ReplenishmentCoverageStatus::Released);
});

it('treats releasing coverage for a purchase order without lines as an idempotent no-op', function (): void {
    $order = PurchaseOrder::factory()->accepted()->create();

    app(PurchaseReplenishmentCoverageService::class)->releaseForOrder($order);

    expect(ReplenishmentCoverage::query()->count())->toBe(0);
});
