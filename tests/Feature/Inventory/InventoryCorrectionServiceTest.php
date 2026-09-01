<?php

declare(strict_types=1);

use App\Enums\InventoryCorrectionStatus;
use App\Enums\InventoryReturnDisposition;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
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

it('corrects a posted receipt with a compensating movement linked to the original movement', function (): void {
    [$receipt, $receiptLine, $warehouse, $variant, $lot, $actor, $originalMovement] =
        completedReceiptForCorrection('10.000000');

    $service = app(InventoryCorrectionService::class);
    $correction = $service->createReceiptCorrection(
        $actor,
        $receipt,
        'Three units were recorded as received but never arrived.',
    );
    $line = $service->addReceiptLine($correction, $receiptLine, '3.000000');
    $posted = $service->post($correction, $actor);

    $stock = correctionStock($variant, $warehouse);
    $compensating = InventoryMovement::query()
        ->where('source_type', 'inventory_correction')
        ->where('source_id', $correction->getKey())
        ->where('source_line_type', 'inventory_correction_line')
        ->where('source_line_id', $line->getKey())
        ->sole();

    expect($posted->status)->toBe(InventoryCorrectionStatus::Posted)
        ->and($stock->on_hand_quantity)->toBe('7.000000')
        ->and(correctionLotSaleable($lot, $warehouse))->toBe('7.000000')
        ->and($compensating->movement_type)->toBe(MovementType::Correction)
        ->and($compensating->quantity)->toBe('-3.000000')
        ->and($compensating->reversal_of_movement_id)->toBe($originalMovement->getKey())
        ->and($line->refresh()->posted_inventory_movement_id)->toBe($compensating->getKey())
        ->and($line->posted_base_quantity)->toBe('3.000000');

    expect($originalMovement->refresh()->movement_type)->toBe(MovementType::Receipt)
        ->and($originalMovement->quantity)->toBe('10.000000')
        ->and($originalMovement->reversal_of_movement_id)->toBeNull();
});

it('caps cumulative receipt corrections against the original posted receipt quantity', function (): void {
    [$receipt, $receiptLine, $warehouse, , , $actor] =
        completedReceiptForCorrection('10.000000');

    $service = app(InventoryCorrectionService::class);

    $first = $service->createReceiptCorrection($actor, $receipt, 'First count correction');
    $service->addReceiptLine($first, $receiptLine, '6.000000');
    $service->post($first, $actor);

    $second = $service->createReceiptCorrection($actor, $receipt, 'Second count correction');

    expect(fn () => $service->addReceiptLine(
        $second,
        $receiptLine,
        '5.000000',
    ))->toThrow(
        DomainException::class,
        'exceeds the remaining correctable receipt quantity',
    );

    expect(correctionStock($receiptLine->productVariant, $warehouse)->on_hand_quantity)
        ->toBe('4.000000');
});

it('posts a receipt correction idempotently without duplicating the compensating movement', function (): void {
    [$receipt, $receiptLine, , , , $actor] = completedReceiptForCorrection('5.000000');
    $service = app(InventoryCorrectionService::class);

    $correction = $service->createReceiptCorrection($actor, $receipt, 'Duplicate confirmation test');
    $line = $service->addReceiptLine($correction, $receiptLine, '2.000000');

    $first = $service->post($correction, $actor);
    $second = $service->post($first->refresh(), $actor);

    expect($second->status)->toBe(InventoryCorrectionStatus::Posted)
        ->and(InventoryMovement::query()
            ->where('source_type', 'inventory_correction')
            ->where('source_id', $correction->getKey())
            ->where('source_line_id', $line->getKey())
            ->count())->toBe(1);
});

it('moves a corrected serialized receipt out of warehouse custody without rewriting receipt history', function (): void {
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
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

    $originalMovement = InventoryMovement::query()
        ->where('source_type', 'inventory_operation')
        ->where('source_id', $receipt->getKey())
        ->where('source_line_id', $receiptLine->getKey())
        ->where('movement_type', MovementType::Receipt->value)
        ->sole();

    $service = app(InventoryCorrectionService::class);
    $correction = $service->createReceiptCorrection($actor, $receipt->refresh(), 'Wrong serial received');
    $service->addReceiptLine($correction, $receiptLine->refresh(), '1');
    $service->post($correction, $actor);

    $correctingMovement = InventoryMovement::query()
        ->where('source_type', 'inventory_correction')
        ->where('source_id', $correction->getKey())
        ->sole();

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::ReturnedToSupplier)
        ->and($unit->warehouse_id)->toBeNull()
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Supplier)
        ->and($unit->custody_reference_id)->toBe($supplier->getKey())
        ->and($correctingMovement->reversal_of_movement_id)->toBe($originalMovement->getKey())
        ->and(correctionStock($variant, $warehouse)->on_hand_quantity)->toBe('0.000000');
});

it('refuses a receipt correction when later stock state prevents a truthful reversal', function (): void {
    [$receipt, $receiptLine, $warehouse, $variant, $lot, $actor] =
        completedReceiptForCorrection('5.000000');

    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '4.000000',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($delivery, $actor);
    $operations->complete($delivery->refresh(), $actor);

    $service = app(InventoryCorrectionService::class);
    $correction = $service->createReceiptCorrection($actor, $receipt, 'Attempt to rewrite consumed history');
    $service->addReceiptLine($correction, $receiptLine, '3.000000');

    expect(fn () => $service->post($correction, $actor))
        ->toThrow(DomainException::class);

    expect(correctionStock($variant, $warehouse)->on_hand_quantity)->toBe('1.000000')
        ->and(InventoryMovement::query()
            ->where('source_type', 'inventory_correction')
            ->where('source_id', $correction->getKey())
            ->count())->toBe(0);
});

it('keeps delivery correction on the customer-return path rather than the receipt correction path', function (): void {
    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);

    expect(fn () => app(InventoryCorrectionService::class)->createReceiptCorrection(
        $actor,
        $delivery,
        'Wrong delivery',
    ))->toThrow(
        DomainException::class,
        'completed receipt operation',
    );

    expect(fn () => app(InventoryReturnService::class)->createCustomerReturn(
        $actor,
        $delivery,
        $warehouse,
        'Correct delivery through customer return',
    ))->not->toThrow(DomainException::class);
});

it('cancels a draft correction without posting any movement', function (): void {
    [$receipt, $receiptLine, , , , $actor] = completedReceiptForCorrection('4.000000');
    $service = app(InventoryCorrectionService::class);
    $correction = $service->createReceiptCorrection($actor, $receipt, 'Draft correction');
    $service->addReceiptLine($correction, $receiptLine, '1.000000');

    $cancelled = $service->cancel($correction, $actor, 'Supervisor rejected the correction.');

    expect($cancelled->status)->toBe(InventoryCorrectionStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Supervisor rejected the correction.')
        ->and(InventoryMovement::query()
            ->where('source_type', 'inventory_correction')
            ->where('source_id', $correction->getKey())
            ->count())->toBe(0);
});

/**
 * @return array{
 *   InventoryOperation,
 *   InventoryOperationLine,
 *   Warehouse,
 *   ProductVariant,
 *   InventoryLot,
 *   User,
 *   InventoryMovement
 * }
 */
function completedReceiptForCorrection(string $quantity): array
{
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();
    $receipt = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $supplier->getKey(),
    ]);
    $line = $receipt->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => $quantity,
        'unit_id' => $variant->unit_id,
        'lot_number' => 'CORRECTION-LOT',
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($receipt, $actor);
    $operations->complete($receipt->refresh(), $actor);

    $line = $line->refresh();
    $lot = InventoryLot::query()->findOrFail($line->inventory_lot_id);
    $movement = InventoryMovement::query()
        ->where('source_type', 'inventory_operation')
        ->where('source_id', $receipt->getKey())
        ->where('source_line_type', 'inventory_operation_line')
        ->where('source_line_id', $line->getKey())
        ->where('movement_type', MovementType::Receipt->value)
        ->sole();

    return [
        $receipt->refresh(),
        $line,
        $warehouse,
        $variant,
        $lot,
        $actor,
        $movement,
    ];
}

function correctionStock(ProductVariant $variant, Warehouse $warehouse): InventoryStock
{
    return InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->sole();
}

function correctionLotSaleable(InventoryLot $lot, Warehouse $warehouse): string
{
    return (string) InventoryLotBalance::query()
        ->where('inventory_lot_id', $lot->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->where('stock_condition', StockCondition::Saleable->value)
        ->value('on_hand_base_quantity');
}

it('requires completed delivery corrections to use customer returns instead of receipt corrections', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '3.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '3.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '3.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $deliveryLine = $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000000',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($delivery, $actor);
    $operations->complete($delivery->refresh(), $actor);

    expect(fn () => app(InventoryCorrectionService::class)->createReceiptCorrection(
        $actor,
        $delivery->refresh(),
        'Delivery correction must use a customer return.',
    ))->toThrow(
        DomainException::class,
        'completed receipt operation',
    );

    $returnService = app(InventoryReturnService::class);
    $return = $returnService->createCustomerReturn($actor, $delivery->refresh(), $warehouse);
    $returnLine = $returnService->addCustomerLine(
        $return,
        $deliveryLine->refresh(),
        '1.000000',
        (int) $lot->getKey(),
    );
    $returnService->inspectLine(
        $returnLine,
        InventoryReturnDisposition::Saleable,
        $actor,
    );
    $returnService->markReady($return, $actor);

    $postedReturn = $returnService->post($return->refresh(), $actor);

    expect($postedReturn->isPosted())->toBeTrue()
        ->and(InventoryMovement::query()
            ->where('movement_type', MovementType::Return->value)
            ->where('source_type', 'inventory_return')
            ->where('source_id', $return->getKey())
            ->count())->toBe(1);
});
