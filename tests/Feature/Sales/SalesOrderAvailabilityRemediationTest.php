<?php

declare(strict_types=1);

use App\Enums\OrderAvailabilityState;
use App\Enums\OrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesProcurementRequirement;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Sales\OrderAvailabilityService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function salesAvailabilityFacts(array $overrides = []): array
{
    return [
        'procurement_outstanding' => 0.0,
        'planned' => 0.0,
        'ready' => 0.0,
        'dispatched' => 0.0,
        'arrived' => 0.0,
        'remaining' => 0.0,
        ...$overrides,
    ];
}

it('projects precise business availability blockers for released sales orders', function (): void {
    $service = app(OrderAvailabilityService::class);
    $order = Order::factory()->make(['status' => OrderStatus::Released]);

    $open = new SalesProcurementRequirement([
        'required_base_quantity' => '10.000000',
        'fulfilled_base_quantity' => '0.000000',
        'status' => 'open',
    ]);

    expect($service->resolve(
        $order,
        new Collection([$open]),
        salesAvailabilityFacts(['procurement_outstanding' => 10.0]),
    ))->toBe(OrderAvailabilityState::InsufficientStock);

    $purchaseOrder = PurchaseOrder::factory()->make([
        'status' => PurchaseOrderStatus::Accepted,
        'sent_at' => now(),
        'supplier_confirmation_required' => true,
    ]);
    $purchaseOrder->setRelation('confirmations', new Collection([
        new SupplierConfirmation(['supplier_reference' => null]),
    ]));
    $purchaseOrder->confirmations->first()->forceFill([
        'confirmation_status' => SupplierConfirmationStatus::Pending,
    ]);

    $open->forceFill([
        'purchase_order_id' => 99,
        'status' => 'purchasing',
    ]);
    $open->setRelation('purchaseOrder', $purchaseOrder);

    expect($service->resolve(
        $order,
        new Collection([$open]),
        salesAvailabilityFacts(['procurement_outstanding' => 10.0]),
    ))->toBe(OrderAvailabilityState::AwaitingSupplierConfirmation)
        ->and($service->resolve(
            $order,
            new Collection([$open]),
            salesAvailabilityFacts([
                'procurement_outstanding' => 8.0,
                'planned' => 2.0,
                'remaining' => 8.0,
            ]),
        ))->toBe(OrderAvailabilityState::PartiallyAvailable)
        ->and($service->resolve(
            $order,
            new Collection,
            salesAvailabilityFacts(['ready' => 10.0]),
        ))->toBe(OrderAvailabilityState::ReadyForDelivery);
});

it('stores direct sales order and line traceability on delivery reservations', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '10.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $order = Order::factory()->create();
    $orderLine = OrderLine::factory()
        ->for($order)
        ->for($variant, 'productVariant')
        ->create([
            'quantity' => '4',
            'unit_id' => $variant->unit_id,
        ]);
    $operation = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
    ]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'order_line_id' => $orderLine->getKey(),
        'quantity' => '4',
        'base_quantity' => '4.000000',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    app(InventoryOperationService::class)->markReady($operation, User::factory()->create());

    $reservation = InventoryReservation::query()->sole();

    expect($reservation->sales_order_id)->toBe($order->getKey())
        ->and($reservation->sales_order_line_id)->toBe($orderLine->getKey())
        ->and($reservation->salesOrder?->getKey())->toBe($order->getKey())
        ->and($reservation->salesOrderLine?->getKey())->toBe($orderLine->getKey())
        ->and($stock->refresh()->reserved_quantity)->toBe('4.000000');
});
