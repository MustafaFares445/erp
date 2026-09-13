<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\PurchaseInboundStatus;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseInboundStatusService;
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

    $this->inboundService = app(PurchaseInboundService::class);
    $this->statusService = app(PurchaseInboundStatusService::class);
    $this->receiving = app(PurchaseOrderReceivingService::class);
    $this->operations = app(InventoryOperationService::class);
});

function phaseFourStatusAllocator(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::InboundAllocate->value);

    return $user;
}

/**
 * @return array{
 *     order: PurchaseOrder,
 *     line: PurchaseOrderLine,
 *     inbound: PurchaseInbound,
 *     inbound_line: PurchaseInboundLine,
 *     warehouse_a: Warehouse,
 *     warehouse_b: Warehouse
 * }
 */
function phaseFourStatusOrder(string $quantity = '100'): array
{
    $variant = ProductVariant::factory()->create();
    /** @var Unit $unit */
    $unit = $variant->unit()->firstOrFail();
    $order = PurchaseOrder::factory()->sent()->create();

    /** @var PurchaseOrderLine $line */
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => $quantity,
        'unit_cost' => '5.00',
        'line_total' => bcmul($quantity, '5.00', 2),
    ]);

    $snapshot = app(QuantityNormalizer::class)->normalize(
        $variant,
        (int) $unit->getKey(),
        $quantity,
    );

    $line->forceFill([
        'transaction_quantity' => $snapshot->transactionQuantity,
        'transaction_unit_id' => $snapshot->transactionUnitId,
        'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
        'base_quantity' => $snapshot->baseQuantity,
        'received_base_quantity' => '0.000000',
    ])->save();

    $inbound = app(PurchaseInboundService::class)->ensureForAccepted($order);
    /** @var PurchaseInboundLine $inboundLine */
    $inboundLine = $inbound->lines()
        ->where('purchase_order_line_id', $line->getKey())
        ->firstOrFail();

    return [
        'order' => $order->refresh(),
        'line' => $line->refresh(),
        'inbound' => $inbound->refresh(),
        'inbound_line' => $inboundLine,
        'warehouse_a' => Warehouse::factory()->create(['is_active' => true]),
        'warehouse_b' => Warehouse::factory()->create(['is_active' => true]),
    ];
}

it('keeps draft receipts out of physical inbound status and advances only after completion', function (): void {
    $context = phaseFourStatusOrder();
    $allocator = phaseFourStatusAllocator();

    /** @var PurchaseInboundAllocation $allocationA */
    $allocationA = $this->inboundService->allocate(
        $allocator,
        $context['inbound_line'],
        $context['warehouse_a'],
        '60',
    );
    $this->inboundService->allocate(
        $allocator,
        $context['inbound_line'],
        $context['warehouse_b'],
        '40',
    );

    $confirmedAt = $context['inbound']->fresh()->allocation_confirmed_at;

    expect($context['inbound']->fresh()->status)->toBe(PurchaseInboundStatus::AwaitingReceipt)
        ->and($confirmedAt)->not->toBeNull();

    $draft = $this->receiving->initiate($this->manager, $context['order'], [[
        'purchase_inbound_allocation_id' => $allocationA->getKey(),
        'quantity' => '20',
    ]]);

    expect($context['inbound']->fresh()->status)->toBe(PurchaseInboundStatus::AwaitingReceipt)
        ->and($context['inbound']->fresh()->completed_at)->toBeNull();

    $this->operations->markReady($draft, $this->manager);
    $this->operations->complete($draft->refresh(), $this->manager);

    $partiallyReceived = $context['inbound']->fresh();

    expect($partiallyReceived->status)->toBe(PurchaseInboundStatus::PartiallyReceived)
        ->and($partiallyReceived->completed_at)->toBeNull()
        ->and($partiallyReceived->allocation_confirmed_at?->equalTo($confirmedAt))->toBeTrue();
});

it('aggregates completed receipts across warehouse allocations and marks the inbound received only at the total', function (): void {
    $context = phaseFourStatusOrder();
    $allocator = phaseFourStatusAllocator();

    $allocationA = $this->inboundService->allocate(
        $allocator,
        $context['inbound_line'],
        $context['warehouse_a'],
        '60',
    );
    $allocationB = $this->inboundService->allocate(
        $allocator,
        $context['inbound_line'],
        $context['warehouse_b'],
        '40',
    );

    foreach ([
        [$allocationA, '20'],
        [$allocationB, '15'],
    ] as [$allocation, $quantity]) {
        $operation = $this->receiving->initiate($this->manager, $context['order']->refresh(), [[
            'purchase_inbound_allocation_id' => $allocation->getKey(),
            'quantity' => $quantity,
        ]]);
        $this->operations->markReady($operation, $this->manager);
        $this->operations->complete($operation->refresh(), $this->manager);
    }

    expect($context['inbound']->fresh()->status)->toBe(PurchaseInboundStatus::PartiallyReceived)
        ->and($context['line']->fresh()->received_base_quantity)->toBe('35.000000')
        ->and($allocationA->fresh()->remainingBaseQuantity())->toBe('40.000000')
        ->and($allocationB->fresh()->remainingBaseQuantity())->toBe('25.000000');

    foreach ([
        [$allocationA, '40'],
        [$allocationB, '25'],
    ] as [$allocation, $quantity]) {
        $operation = $this->receiving->initiate($this->manager, $context['order']->refresh(), [[
            'purchase_inbound_allocation_id' => $allocation->getKey(),
            'quantity' => $quantity,
        ]]);
        $this->operations->markReady($operation, $this->manager);
        $this->operations->complete($operation->refresh(), $this->manager);
    }

    $received = $context['inbound']->fresh();

    expect($received->status)->toBe(PurchaseInboundStatus::Received)
        ->and($received->completed_at)->not->toBeNull()
        ->and($context['line']->fresh()->received_base_quantity)->toBe('100.000000');

    $completedAt = $received->completed_at;
    $this->statusService->synchronize($received);

    expect($received->fresh()->completed_at?->equalTo($completedAt))->toBeTrue();
});

it('does not regress partially received state when the remaining quantity is allocated later', function (): void {
    $context = phaseFourStatusOrder();
    $allocator = phaseFourStatusAllocator();

    $allocationA = $this->inboundService->allocate(
        $allocator,
        $context['inbound_line'],
        $context['warehouse_a'],
        '60',
    );

    expect($context['inbound']->fresh()->status)->toBe(PurchaseInboundStatus::AwaitingAllocation);

    $operation = $this->receiving->initiate($this->manager, $context['order'], [[
        'purchase_inbound_allocation_id' => $allocationA->getKey(),
        'quantity' => '20',
    ]]);
    $this->operations->markReady($operation, $this->manager);
    $this->operations->complete($operation->refresh(), $this->manager);

    expect($context['inbound']->fresh()->status)->toBe(PurchaseInboundStatus::PartiallyReceived);

    $this->inboundService->allocate(
        $allocator,
        $context['inbound_line'],
        $context['warehouse_b'],
        '40',
    );

    $afterAllocation = $context['inbound']->fresh();

    expect($afterAllocation->status)->toBe(PurchaseInboundStatus::PartiallyReceived)
        ->and($afterAllocation->allocation_confirmed_at)->not->toBeNull();
});

it('keeps cancelled inbound aggregates terminal when facts are synchronized', function (): void {
    $context = phaseFourStatusOrder();

    $context['inbound']->forceFill([
        'status' => PurchaseInboundStatus::Cancelled,
        'completed_at' => now(),
    ])->save();

    $completedAt = $context['inbound']->fresh()->completed_at;
    $synchronized = $this->statusService->synchronize($context['inbound']);

    expect($synchronized->status)->toBe(PurchaseInboundStatus::Cancelled)
        ->and($synchronized->completed_at?->equalTo($completedAt))->toBeTrue();
});
