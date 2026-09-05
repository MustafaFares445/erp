<?php

declare(strict_types=1);

use App\Enums\ConditionChangeReason;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCorrectionService;
use App\Services\Inventory\InventoryLotReconciliationService;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// IN-16: every post-commit correction to an already-posted document — receipt, delivery, or
// internal transfer — must replay the movement ledger to the correct balance, and the canonical
// lot/condition/serial reconciliation invariants (WP-2.4) must still hold once it has posted.

it('replays a corrected receipt to the right balance and passes canonical reconciliation', function (): void {
    [$receipt, $receiptLine, $warehouse, $variant] = postedReceiptDocument('10.000000');
    $actor = User::factory()->create();

    $service = app(InventoryCorrectionService::class);
    $correction = $service->createReceiptCorrection(
        $actor,
        $receipt,
        'Three units were recorded as received but never arrived.',
    );
    $service->addReceiptLine($correction, $receiptLine, '3.000000');
    $service->post($correction, $actor);

    expect((float) postedDocumentStock($variant, $warehouse)->on_hand_quantity)->toBe(7.0);

    assertCleanReconciliation();
});

it('replays a corrected delivery to the right balance and passes canonical reconciliation', function (): void {
    [$delivery, $deliveryLine, $warehouse, $variant, $actor] = postedDeliveryDocument('10.000000', '6.000000');

    $service = app(InventoryCorrectionService::class);
    $correction = $service->createDeliveryCorrection(
        $actor,
        $delivery,
        ConditionChangeReason::Other,
        'Delivery was keyed against the wrong sales order.',
    );
    $service->addDeliveryLine($correction, $deliveryLine, '6.000000');
    $service->post($correction, $actor);

    // 10 received - 6 delivered + 6 corrected back = 10 (the full delivery is undone).
    expect((float) postedDocumentStock($variant, $warehouse)->on_hand_quantity)->toBe(10.0);

    assertCleanReconciliation();
});

it('replays a corrected transfer to the right balance and passes canonical reconciliation', function (): void {
    [$transfer, $transferLine, $source, $wrongDestination, $variant, $actor] = postedTransferDocument('10.000000');
    $correctDestination = Warehouse::factory()->create();

    $service = app(InventoryCorrectionService::class);
    $correction = $service->createTransferCorrection(
        $actor,
        $transfer,
        ConditionChangeReason::Other,
        'Transfer was keyed to the wrong destination warehouse.',
        (int) $correctDestination->getKey(),
    );
    $service->addTransferLine($correction, $transferLine, '10.000000');
    $service->post($correction, $actor);

    expect((float) postedDocumentStock($variant, $wrongDestination)->on_hand_quantity)->toBe(0.0)
        ->and((float) postedDocumentStock($variant, $correctDestination)->on_hand_quantity)->toBe(10.0)
        ->and(InventoryStock::query()->where('product_variant_id', $variant->getKey())->where('warehouse_id', $source->getKey())->sole()->on_hand_quantity)
        ->toBe('0.000000');

    assertCleanReconciliation();
});

function assertCleanReconciliation(): void
{
    $report = app(InventoryLotReconciliationService::class)->inspect();

    expect($report['errors'])->toBe([]);
}

/**
 * @return array{InventoryOperation, InventoryOperationLine, Warehouse, ProductVariant}
 */
function postedReceiptDocument(string $quantity): array
{
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();

    $receipt = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $supplier->getKey(),
    ]);
    $receiptLine = $receipt->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => $quantity,
        'unit_id' => $variant->unit_id,
        'lot_number' => 'POSTED-DOC-RECEIPT-LOT',
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($receipt, $actor);
    $operations->complete($receipt->refresh(), $actor);

    return [$receipt->refresh(), $receiptLine->refresh(), $warehouse, $variant];
}

/**
 * @return array{InventoryOperation, InventoryOperationLine, Warehouse, ProductVariant, User}
 */
function postedDeliveryDocument(string $receivedQuantity, string $deliveredQuantity): array
{
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();

    $receipt = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $supplier->getKey(),
    ]);
    $receiptLine = $receipt->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => $receivedQuantity,
        'unit_id' => $variant->unit_id,
        'lot_number' => 'POSTED-DOC-DELIVERY-LOT',
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($receipt, $actor);
    $operations->complete($receipt->refresh(), $actor);

    $lot = InventoryLot::query()->findOrFail($receiptLine->refresh()->inventory_lot_id);

    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    $deliveryLine = $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => $deliveredQuantity,
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    $operations->markReady($delivery, $actor);
    $operations->complete($delivery->refresh(), $actor);

    return [$delivery->refresh(), $deliveryLine->refresh(), $warehouse, $variant, $actor];
}

/**
 * @return array{InventoryOperation, InventoryOperationLine, Warehouse, Warehouse, ProductVariant, User}
 */
function postedTransferDocument(string $quantity): array
{
    $source = Warehouse::factory()->create();
    $wrongDestination = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();

    $receipt = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $source->getKey(),
        'supplier_id' => $supplier->getKey(),
    ]);
    $receiptLine = $receipt->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => $quantity,
        'unit_id' => $variant->unit_id,
        'lot_number' => 'POSTED-DOC-TRANSFER-LOT',
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($receipt, $actor);
    $operations->complete($receipt->refresh(), $actor);

    $lot = InventoryLot::query()->findOrFail($receiptLine->refresh()->inventory_lot_id);

    $transfer = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $wrongDestination->getKey(),
    ]);
    $transferLine = $transfer->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => $quantity,
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    $operations->markReady($transfer, $actor);
    $operations->dispatch($transfer->refresh(), $actor);
    $operations->complete($transfer->refresh(), $actor);

    return [$transfer->refresh(), $transferLine->refresh(), $source, $wrongDestination, $variant, $actor];
}

function postedDocumentStock(ProductVariant $variant, Warehouse $warehouse): InventoryStock
{
    return InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->sole();
}
