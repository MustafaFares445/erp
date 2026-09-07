<?php

declare(strict_types=1);

use App\Enums\ConditionChangeReason;
use App\Enums\InventoryCorrectionStatus;
use App\Enums\InventoryReturnDisposition;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCorrectionService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('caps a delivery correction to account for prior corrections and prior customer returns', function (): void {
    [$delivery, $deliveryLine, $warehouse, $variant, , $actor] = completedDeliveryForCorrection('10.000000', '10.000000');

    $service = app(InventoryCorrectionService::class);

    $firstCorrection = $service->createDeliveryCorrection(
        $actor,
        $delivery,
        ConditionChangeReason::Other,
        'Keyed the wrong quantity for the first three units.',
    );
    $service->addDeliveryLine($firstCorrection, $deliveryLine, '3.000000');
    $service->post($firstCorrection, $actor);

    $returnService = app(InventoryReturnService::class);
    $return = $returnService->createCustomerReturn($actor, $delivery->refresh(), $warehouse);
    $returnLine = $returnService->addCustomerLine(
        $return,
        $deliveryLine->refresh(),
        '2.000000',
        (int) $deliveryLine->refresh()->inventory_lot_id,
    );
    $returnService->inspectLine($returnLine, InventoryReturnDisposition::Saleable, $actor);
    $returnService->markReady($return, $actor);
    $returnService->post($return->refresh(), $actor);

    $secondCorrection = $service->createDeliveryCorrection(
        $actor,
        $delivery->refresh(),
        ConditionChangeReason::Other,
        'Attempt to correct beyond what remains.',
    );

    expect(fn () => $service->addDeliveryLine($secondCorrection, $deliveryLine->refresh(), '5.000001'))
        ->toThrow(DomainException::class, 'prior corrections and customer returns are accounted for');

    // Exactly what remains (10 delivered - 3 corrected - 2 returned = 5) is still correctable.
    $line = $service->addDeliveryLine($secondCorrection, $deliveryLine->refresh(), '5.000000');
    expect($line->base_quantity)->toBe('5.000000');
});

it('restores the balance at saleable condition and writes correction movements referencing the delivery', function (): void {
    [$delivery, $deliveryLine, $warehouse, $variant, $lot, $actor] = completedDeliveryForCorrection('10.000000', '4.000000');

    $stockBeforeCorrection = deliveryCorrectionStock($variant, $warehouse)->on_hand_quantity;

    $service = app(InventoryCorrectionService::class);
    $correction = $service->createDeliveryCorrection(
        $actor,
        $delivery,
        ConditionChangeReason::Other,
        'Delivery was keyed against the wrong sales order.',
    );
    $line = $service->addDeliveryLine($correction, $deliveryLine, '4.000000');
    $posted = $service->post($correction, $actor);

    $originalMovement = InventoryMovement::query()
        ->where('source_type', 'inventory_operation')
        ->where('source_id', $delivery->getKey())
        ->where('source_line_id', $deliveryLine->getKey())
        ->where('movement_type', MovementType::Sale->value)
        ->sole();

    $compensating = InventoryMovement::query()
        ->where('source_type', 'inventory_correction')
        ->where('source_id', $correction->getKey())
        ->where('source_line_type', 'inventory_correction_line')
        ->where('source_line_id', $line->getKey())
        ->sole();

    expect($posted->status)->toBe(InventoryCorrectionStatus::Posted)
        ->and((float) deliveryCorrectionStock($variant, $warehouse)->on_hand_quantity)
        ->toBe((float) $stockBeforeCorrection + 4.0)
        ->and($compensating->movement_type)->toBe(MovementType::Correction)
        ->and($compensating->quantity)->toBe('4.000000')
        ->and($compensating->warehouse_id)->toBe($warehouse->getKey())
        ->and($compensating->reversal_of_movement_id)->toBe($originalMovement->getKey())
        ->and($compensating->stock_condition_to)->toBe(StockCondition::Saleable)
        ->and($line->refresh()->posted_inventory_movement_id)->toBe($compensating->getKey())
        ->and($line->posted_base_quantity)->toBe('4.000000');
});

it('returns serialized custody to the warehouse when correcting a serialized delivery', function (): void {
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $actor = User::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'status' => SerializedInventoryUnitStatus::Pending,
        'warehouse_id' => null,
    ]);

    $receipt = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $supplier->getKey(),
    ]);
    $receiptLine = $receipt->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($receipt, $actor);
    $operations->complete($receipt->refresh(), $actor);

    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    $deliveryLine = $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $receiptLine->refresh()->inventory_lot_id,
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    $operations->markReady($delivery, $actor);
    $operations->complete($delivery->refresh(), $actor);

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::Delivered)
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Customer);

    $service = app(InventoryCorrectionService::class);
    $correction = $service->createDeliveryCorrection(
        $actor,
        $delivery->refresh(),
        ConditionChangeReason::Other,
        'Wrong serial delivered to the customer.',
    );
    $service->addDeliveryLine($correction, $deliveryLine->refresh(), '1');
    $service->post($correction, $actor);

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::Available)
        ->and($unit->warehouse_id)->toBe($warehouse->getKey())
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Warehouse)
        ->and($unit->stock_condition)->toBe(StockCondition::Saleable);
});

it('throws when correcting a delivery that has not completed', function (): void {
    $warehouse = Warehouse::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $actor = User::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
        'customer_id' => $customer->getKey(),
    ]);

    expect(fn () => app(InventoryCorrectionService::class)->createDeliveryCorrection(
        $actor,
        $delivery,
        ConditionChangeReason::Other,
        'Attempt to correct a draft delivery.',
    ))->toThrow(DomainException::class, 'completed delivery operation');
});

it('refuses a delivery correction whose reason signals goods physically came back', function (): void {
    [$delivery, , , , , $actor] = completedDeliveryForCorrection('5.000000', '5.000000');

    expect(fn () => app(InventoryCorrectionService::class)->createDeliveryCorrection(
        $actor,
        $delivery,
        ConditionChangeReason::CustomerReturnInspection,
        'The customer sent it back.',
    ))->toThrow(DomainException::class, 'customer return, not a delivery correction');
});

/**
 * @return array{
 *   InventoryOperation,
 *   InventoryOperationLine,
 *   Warehouse,
 *   ProductVariant,
 *   InventoryLot,
 *   User
 * }
 */
function completedDeliveryForCorrection(string $receivedQuantity, string $deliveredQuantity): array
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
        'lot_number' => 'DELIVERY-CORRECTION-LOT',
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

    return [
        $delivery->refresh(),
        $deliveryLine->refresh(),
        $warehouse,
        $variant,
        $lot,
        $actor,
    ];
}

function deliveryCorrectionStock(ProductVariant $variant, Warehouse $warehouse): InventoryStock
{
    return InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->sole();
}
