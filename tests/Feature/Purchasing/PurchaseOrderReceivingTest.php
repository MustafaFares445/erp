<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotReceivable;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    $this->receiving = app(PurchaseOrderReceivingService::class);
    $this->operations = app(InventoryOperationService::class);
    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);
});

/**
 * A sent order for one variant, ready to receive against.
 *
 * @return array{0: PurchaseOrder, 1: ProductVariant, 2: Unit}
 */
function receivableOrder(float $quantity = 10, string $unitCost = '5.00'): array
{
    $variant = ProductVariant::factory()->create();
    $unit = Unit::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $order = PurchaseOrder::factory()->sent()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);

    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => $quantity,
        'unit_cost' => $unitCost,
        'line_total' => (float) $unitCost * $quantity,
    ]);

    return [$order->refresh(), $variant, $unit];
}

it('opens a draft receipt pointing back at the order, pre-filled from what is outstanding (FR-037)', function (): void {
    [$order, $variant, $unit] = receivableOrder(10);

    $operation = $this->receiving->initiate($this->manager, $order);

    expect($operation->operation_type)->toBe(OperationType::Receipt)
        ->and($operation->stage)->toBe(OperationStage::Draft)
        ->and($operation->source_document_type)->toBe(PurchaseOrder::class)
        ->and($operation->source_document_id)->toBe($order->getKey())
        ->and($operation->supplier_id)->toBe($order->supplier_id)
        ->and($operation->supplier_reference)->toBe($order->purchase_order_number)
        ->and($operation->destination_warehouse_id)->toBe($order->destination_warehouse_id)
        ->and($operation->lines)->toHaveCount(1);

    $line = $operation->lines->first();

    expect((float) $line->quantity)->toBe(10.0)
        ->and($line->product_variant_id)->toBe($variant->getKey())
        ->and($line->unit_id)->toBe($unit->getKey());
});

it('moves no stock when the receipt is merely opened', function (): void {
    [$order] = receivableOrder();

    $this->receiving->initiate($this->manager, $order);

    // Purchasing initiates; Inventory posts. Nothing has arrived yet (R-001).
    expect(InventoryMovement::query()->count())->toBe(0);
});

it('refuses a receipt against an order that is not receivable (V-12, FR-036)', function (): void {
    foreach (PurchaseOrderStatus::cases() as $status) {
        if ($status->isReceivable()) {
            continue;
        }

        $order = PurchaseOrder::factory()->create(['status' => $status]);

        expect(fn (): InventoryOperation => $this->receiving->initiate($this->manager, $order))
            ->toThrow(AuthorizationException::class, 'This action is unauthorized.');
    }
});

it('refuses a receipt into a warehouse deactivated since the order was sent (FR-044)', function (): void {
    [$order] = receivableOrder();

    Warehouse::query()->whereKey($order->destination_warehouse_id)->update(['is_active' => false]);

    expect(fn (): InventoryOperation => $this->receiving->initiate($this->manager, $order->refresh()))
        ->toThrow(PurchaseOrderNotReceivable::class);
});

it('advances the order to received and stocks the warehouse when the receipt completes', function (): void {
    [$order, $variant] = receivableOrder(10, '5.00');

    $operation = $this->receiving->initiate($this->manager, $order);
    $this->operations->markReady($operation, $this->manager);
    $this->operations->complete($operation->refresh(), $this->manager);

    $order->refresh();
    $line = $order->lines()->firstOrFail();

    expect($order->status)->toBe(PurchaseOrderStatus::Received)
        ->and((float) $line->quantity_received)->toBe(10.0)
        ->and($line->last_received_unit_cost)->toBe('5.00')
        // The decrement — or in this case increment — was written by the
        // Inventory path, not by anything in this feature.
        ->and(InventoryMovement::query()->count())->toBeGreaterThan(0);
});

it('advances to partially received when only part of the order arrives', function (): void {
    [$order, $variant, $unit] = receivableOrder(10, '5.00');

    $operation = $this->receiving->initiate($this->manager, $order);
    $operation->lines()->firstOrFail()->update(['quantity' => 4]);

    $this->operations->markReady($operation->refresh(), $this->manager);
    $this->operations->complete($operation->refresh(), $this->manager);

    $order->refresh();
    $line = $order->lines()->firstOrFail();

    expect($order->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and((float) $line->quantity_received)->toBe(4.0)
        ->and($line->outstandingQuantity())->toBe(6.0);
});

it('pre-fills a second receipt with only what is still outstanding', function (): void {
    [$order] = receivableOrder(10, '5.00');

    $first = $this->receiving->initiate($this->manager, $order);
    $first->lines()->firstOrFail()->update(['quantity' => 4]);
    $this->operations->markReady($first->refresh(), $this->manager);
    $this->operations->complete($first->refresh(), $this->manager);

    $second = $this->receiving->initiate($this->manager, $order->refresh());

    expect((float) $second->lines()->firstOrFail()->quantity)->toBe(6.0);
});

it('completes the order across two partial receipts', function (): void {
    [$order] = receivableOrder(10, '5.00');

    foreach ([4, 6] as $quantity) {
        $operation = $this->receiving->initiate($this->manager, $order->refresh());
        $operation->lines()->firstOrFail()->update(['quantity' => $quantity]);
        $this->operations->markReady($operation->refresh(), $this->manager);
        $this->operations->complete($operation->refresh(), $this->manager);
    }

    $order->refresh();

    expect($order->status)->toBe(PurchaseOrderStatus::Received)
        ->and((float) $order->lines()->firstOrFail()->quantity_received)->toBe(10.0);
});

it('leaves a fully received line out of a further receipt entirely', function (): void {
    // A receipt line of zero would have to be either ignored or rejected
    // downstream; omitting it says the same thing without the ambiguity.
    [$order] = receivableOrder(10, '5.00');

    $operation = $this->receiving->initiate($this->manager, $order);
    $this->operations->markReady($operation, $this->manager);
    $this->operations->complete($operation->refresh(), $this->manager);

    // The order is now `received`, which is terminal, so a further receipt is
    // refused outright rather than opening an empty one.
    expect(fn (): InventoryOperation => $this->receiving->initiate($this->manager, $order->refresh()))
        ->toThrow(AuthorizationException::class);
});

it('ignores a completed receipt that has no purchase order behind it', function (): void {
    // A receipt raised directly in Inventory must pass straight through the
    // listener without touching anything purchasing owns.
    $warehouse = Warehouse::factory()->create();
    $operation = InventoryOperation::query()->create([
        'operation_type' => OperationType::Receipt,
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);
    $operation->lines()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity' => 3,
    ]);

    $this->operations->markReady($operation->refresh(), $this->manager);
    $completed = $this->operations->complete($operation->refresh(), $this->manager);

    expect($completed->stage)->toBe(OperationStage::Done)
        ->and(PurchaseOrder::query()->count())->toBe(0);
});

it('refuses receipt initiation to a role without the receive permission', function (): void {
    $reviewer = User::factory()->create();
    $reviewer->assignRole(DashboardRole::Reviewer->value);

    [$order] = receivableOrder();

    expect(fn (): InventoryOperation => $this->receiving->initiate($reviewer, $order))
        ->toThrow(AuthorizationException::class);
});
