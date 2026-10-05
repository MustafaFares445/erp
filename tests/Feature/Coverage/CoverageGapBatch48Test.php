<?php

declare(strict_types=1);

use App\Data\Purchasing\PurchaseOrderWorkflowData;
use App\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Models\Bill;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Services\Purchasing\PurchaseOrderWorkflowProjectionStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coveragePurchaseOrderWorkflowData(
    string $nextOwner,
    string $nextAction,
    string $confirmed = '1.000000',
    string $received = '0.000000',
    ?string $blocker = null,
    string $supplierState = 'Confirmed',
): PurchaseOrderWorkflowData {
    return new PurchaseOrderWorkflowData(
        businessState: 'Coverage',
        supplierState: $supplierState,
        logisticsState: 'Coverage',
        financialState: 'Coverage',
        orderedBaseQuantity: $confirmed,
        confirmedBaseQuantity: $confirmed,
        backorderedBaseQuantity: '0.000000',
        unavailableBaseQuantity: '0.000000',
        allocatedBaseQuantity: $confirmed,
        receiptInProgressBaseQuantity: '0.000000',
        receivedBaseQuantity: $received,
        remainingConfirmedBaseQuantity: '0.000000',
        billTotal: '0.00',
        paidTotal: '0.00',
        outstandingTotal: '0.00',
        blocker: $blocker,
        nextOwner: $nextOwner,
        nextAction: $nextAction,
    );
}

function bindPurchaseOrderWorkflowProjection(PurchaseOrderWorkflowData $projection): void
{
    app()->instance(PurchaseOrderWorkflowProjectionStore::class, new readonly class($projection)
    {
        public function __construct(private PurchaseOrderWorkflowData $projection) {}

        public function project(PurchaseOrder $record): PurchaseOrderWorkflowData
        {
            return $this->projection;
        }
    });
}

it('covers purchase order table stage colors and receiving descriptions', function (): void {
    $stageColor = new ReflectionMethod(PurchaseOrdersTable::class, 'stageColor');
    $receivingDescription = new ReflectionMethod(PurchaseOrdersTable::class, 'receivingDescription');

    expect($stageColor->invoke(null, coveragePurchaseOrderWorkflowData('Inventory', 'Allocate')))
        ->toBe('primary')
        ->and($stageColor->invoke(null, coveragePurchaseOrderWorkflowData('Accounting', 'Review bill')))
        ->toBe('warning')
        ->and($receivingDescription->invoke(null, coveragePurchaseOrderWorkflowData('Inventory', 'Receive', '2.000000', '2.000000')))
        ->toBe('Fully received')
        ->and($receivingDescription->invoke(null, coveragePurchaseOrderWorkflowData('Inventory', 'Receive', '2.000000', '1.000000')))
        ->toBe('Partially received');
});

it('routes inventory next action to the purchase inbound', function (): void {
    $order = PurchaseOrder::factory()->create();
    $inbound = PurchaseInbound::factory()->create(['purchase_order_id' => $order->getKey()]);
    $order->setRelation('purchaseInbound', $inbound);

    bindPurchaseOrderWorkflowProjection(coveragePurchaseOrderWorkflowData('Inventory', 'Allocate destination warehouse quantities'));

    $url = new ReflectionMethod(PurchaseOrdersTable::class, 'nextActionUrl')->invoke(null, $order);

    expect($url)->toContain((string) $inbound->getKey());
});

it('routes accounting next action to an existing bill or bill creation', function (): void {
    $existingOrder = PurchaseOrder::factory()->create();
    $bill = Bill::factory()->forPurchaseOrder($existingOrder)->create();
    $existingOrder->setRelation('bills', new Collection([$bill]));

    bindPurchaseOrderWorkflowProjection(coveragePurchaseOrderWorkflowData('Accounting', 'Review supplier bill and payment'));

    $nextActionUrl = new ReflectionMethod(PurchaseOrdersTable::class, 'nextActionUrl');

    expect($nextActionUrl->invoke(null, $existingOrder))->toContain((string) $bill->getKey());

    $newOrder = PurchaseOrder::factory()->create();
    $newOrder->setRelation('bills', new Collection);

    bindPurchaseOrderWorkflowProjection(coveragePurchaseOrderWorkflowData('Accounting', 'Create supplier bill'));

    expect($nextActionUrl->invoke(null, $newOrder))
        ->toContain('action=create')
        ->toContain('purchase_order_id');
});

it('routes purchasing next action to a pending supplier confirmation', function (): void {
    $order = PurchaseOrder::factory()->create();
    $confirmation = SupplierConfirmation::factory()->create([
        'purchase_order_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
    ]);
    $order->setRelation('confirmations', new Collection([$confirmation]));

    bindPurchaseOrderWorkflowProjection(coveragePurchaseOrderWorkflowData('Purchasing', 'Record supplier response'));

    $url = new ReflectionMethod(PurchaseOrdersTable::class, 'nextActionUrl')->invoke(null, $order);

    expect($url)->toContain((string) $confirmation->getKey());
});
