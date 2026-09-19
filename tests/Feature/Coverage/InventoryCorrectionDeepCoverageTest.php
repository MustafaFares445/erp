<?php

declare(strict_types=1);

use App\Enums\ConditionChangeReason;
use App\Enums\InventoryCorrectionStatus;
use App\Enums\InventoryCorrectionType;
use App\Enums\MovementType;
use App\Enums\StockCondition;
use App\Models\InventoryCorrection;
use App\Models\InventoryCorrectionLine;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function correctionCoverageInvoke(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(InventoryCorrectionService::class, $method)
        ->invoke(app(InventoryCorrectionService::class), ...$arguments);
}
function correctionCoverageMovementFixture(MovementType $type): array
{
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $operation = match ($type) {
        MovementType::Receipt => InventoryOperation::factory()->receipt()->done()->create(),
        MovementType::Sale => InventoryOperation::factory()->delivery()->done()->create(),
        default => InventoryOperation::factory()->internalTransfer()->done()->create(),
    };
    $line = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '2.000000',
    ]);
    $movement = InventoryMovement::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => $type,
        'quantity' => $type === MovementType::Sale ? '-2.000000' : '2.000000',
        'base_quantity_delta' => $type === MovementType::Sale ? '-2.000000' : '2.000000',
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
        'source_line_type' => 'inventory_operation_line',
        'source_line_id' => $line->getKey(),
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'stock_condition_from' => $type === MovementType::Sale ? StockCondition::Saleable : null,
        'stock_condition_to' => $type === MovementType::Sale ? null : StockCondition::Saleable,
    ]);
    $correctionType = match ($type) {
        MovementType::Receipt => InventoryCorrectionType::Receipt,
        MovementType::Sale => InventoryCorrectionType::Delivery,
        default => InventoryCorrectionType::Transfer,
    };
    $correction = InventoryCorrection::factory()->create([
        'correction_type' => $correctionType,
        'original_inventory_operation_id' => $operation->getKey(),
    ]);

    return [$correction, $operation, $line, $movement, $variant, $warehouse];
}

it('covers correction document validation guards', function (): void {
    $service = app(InventoryCorrectionService::class);
    $actor = User::factory()->create();
    $receipt = InventoryOperation::factory()->receipt()->done()->create();
    $delivery = InventoryOperation::factory()->delivery()->done()->create();

    expect(fn () => $service->createReceiptCorrection($actor, $receipt, '   '))
        ->toThrow(DomainException::class, 'requires a reason')
        ->and(fn () => $service->createDeliveryCorrection($actor, $delivery, ConditionChangeReason::Other, '   '))
        ->toThrow(DomainException::class, 'requires a reason');
});
it('covers transfer correction target and state guards', function (): void {
    $service = app(InventoryCorrectionService::class);
    $actor = User::factory()->create();
    $wrongType = InventoryOperation::factory()->delivery()->done()->create();

    expect(fn () => $service->createTransferCorrection($actor, $wrongType, ConditionChangeReason::Other, 'wrong type'))
        ->toThrow(DomainException::class, 'completed internal transfer');

    $draft = InventoryOperation::factory()->internalTransfer()->draft()->create();
    expect(fn () => $service->createTransferCorrection($actor, $draft, ConditionChangeReason::Other, 'not done'))
        ->toThrow(DomainException::class, 'completed internal transfer');

    $transfer = InventoryOperation::factory()->internalTransfer()->done()->create();
    $inactive = Warehouse::factory()->create(['is_active' => false]);
    expect(fn () => $service->createTransferCorrection($actor, $transfer, ConditionChangeReason::Other, 'inactive', (int) $inactive->getKey()))
        ->toThrow(DomainException::class, 'active warehouse');

    expect(fn () => $service->createTransferCorrection($actor, $transfer, ConditionChangeReason::Other, 'same', (int) $transfer->destination_warehouse_id))
        ->toThrow(DomainException::class, 'differ from the original destination');

    expect(fn () => $service->createTransferCorrection($actor, $transfer, ConditionChangeReason::Other, '   '))
        ->toThrow(DomainException::class, 'requires a reason');
});
it('covers receipt correction line quantity condition and serialized guards', function (): void {
    [$correction, , $line, $movement] = correctionCoverageMovementFixture(MovementType::Receipt);

    expect(fn (): mixed => correctionCoverageInvoke('assertLineCanBeCorrected', $correction, $line, $movement, 'not-a-number', false))
        ->toThrow(DomainException::class, 'must be numeric')
        ->and(fn (): mixed => correctionCoverageInvoke('assertLineCanBeCorrected', $correction, $line, $movement, '3.000000', false))
        ->toThrow(DomainException::class, 'exceeds the remaining');

    $movement->stock_condition_to = StockCondition::Damaged;
    expect(fn (): mixed => correctionCoverageInvoke('assertLineCanBeCorrected', $correction, $line, $movement, '1.000000', false))
        ->toThrow(DomainException::class, 'saleable receipt postings');

    $movement->stock_condition_to = StockCondition::Saleable;
    $movement->serialized_inventory_unit_id = 999999;
    expect(fn (): mixed => correctionCoverageInvoke('assertLineCanBeCorrected', $correction, $line, $movement, '2.000000', false))
        ->toThrow(DomainException::class, 'exactly one unit')
        ->and(fn (): mixed => correctionCoverageInvoke('assertLineCanBeCorrected', $correction, $line, $movement, '1.000000', false))
        ->toThrow(DomainException::class, 'no longer in the original receipt allocation');
});
it('covers delivery correction line quantity condition and serialized guards', function (): void {
    [$correction, , $line, $movement] = correctionCoverageMovementFixture(MovementType::Sale);

    expect(fn (): mixed => correctionCoverageInvoke('assertDeliveryLineCanBeCorrected', $correction, $line, $movement, 'not-a-number', false))
        ->toThrow(DomainException::class, 'must be numeric')
        ->and(fn (): mixed => correctionCoverageInvoke('assertDeliveryLineCanBeCorrected', $correction, $line, $movement, '3.000000', false))
        ->toThrow(DomainException::class, 'exceeds the remaining');

    $movement->stock_condition_from = StockCondition::Damaged;
    expect(fn (): mixed => correctionCoverageInvoke('assertDeliveryLineCanBeCorrected', $correction, $line, $movement, '1.000000', false))
        ->toThrow(DomainException::class, 'saleable delivery postings');

    $movement->stock_condition_from = StockCondition::Saleable;
    $movement->serialized_inventory_unit_id = 999999;
    expect(fn (): mixed => correctionCoverageInvoke('assertDeliveryLineCanBeCorrected', $correction, $line, $movement, '2.000000', false))
        ->toThrow(DomainException::class, 'exactly one unit')
        ->and(fn (): mixed => correctionCoverageInvoke('assertDeliveryLineCanBeCorrected', $correction, $line, $movement, '1.000000', false))
        ->toThrow(DomainException::class, 'no longer in the original delivery allocation');
});
it('covers transfer correction line quantity condition and serialized guards', function (): void {
    [$correction, , $line, $movement] = correctionCoverageMovementFixture(MovementType::Transfer);

    expect(fn (): mixed => correctionCoverageInvoke('assertTransferLineCanBeCorrected', $correction, $line, $movement, 'not-a-number', false))
        ->toThrow(DomainException::class, 'must be numeric')
        ->and(fn (): mixed => correctionCoverageInvoke('assertTransferLineCanBeCorrected', $correction, $line, $movement, '3.000000', false))
        ->toThrow(DomainException::class, 'exceeds the remaining');

    $movement->stock_condition_to = StockCondition::Damaged;
    expect(fn (): mixed => correctionCoverageInvoke('assertTransferLineCanBeCorrected', $correction, $line, $movement, '1.000000', false))
        ->toThrow(DomainException::class, 'saleable transfer postings');

    $movement->stock_condition_to = StockCondition::Saleable;
    $movement->serialized_inventory_unit_id = 999999;
    expect(fn (): mixed => correctionCoverageInvoke('assertTransferLineCanBeCorrected', $correction, $line, $movement, '2.000000', false))
        ->toThrow(DomainException::class, 'exactly one unit')
        ->and(fn (): mixed => correctionCoverageInvoke('assertTransferLineCanBeCorrected', $correction, $line, $movement, '1.000000', false))
        ->toThrow(DomainException::class, 'no longer in the original transfer allocation');
});

it('covers correction identifier document state and mismatched-line guards', function (): void {
    $service = app(InventoryCorrectionService::class);
    $actor = User::factory()->create();

    expect(fn (): InventoryCorrection => $service->createReceiptCorrection($actor, new InventoryOperation, 'coverage'))
        ->toThrow(LogicException::class, 'Inventory operation identifiers must be integers.')
        ->and(fn (): InventoryCorrection => $service->createDeliveryCorrection(
            $actor,
            new InventoryOperation,
            ConditionChangeReason::Other,
            'coverage',
        ))->toThrow(LogicException::class, 'Inventory operation identifiers must be integers.')
        ->and(fn (): InventoryCorrection => $service->createTransferCorrection(
            $actor,
            new InventoryOperation,
            ConditionChangeReason::Other,
            'coverage',
        ))->toThrow(LogicException::class, 'Inventory operation identifiers must be integers.');

    $deliveryCorrection = InventoryCorrection::factory()->create([
        'correction_type' => InventoryCorrectionType::Delivery,
    ]);
    expect(fn () => $service->addDeliveryLine($deliveryCorrection, new InventoryOperationLine, '1.000000'))
        ->toThrow(LogicException::class, 'Inventory operation line identifiers must be integers.');

    $transferCorrection = InventoryCorrection::factory()->create([
        'correction_type' => InventoryCorrectionType::Transfer,
    ]);
    expect(fn () => $service->addTransferLine($transferCorrection, new InventoryOperationLine, '1.000000'))
        ->toThrow(LogicException::class, 'Inventory operation line identifiers must be integers.');

    $posted = InventoryCorrection::factory()->create();
    $postedLine = InventoryCorrectionLine::factory()->create([
        'inventory_correction_id' => $posted->getKey(),
    ]);
    $posted->forceFill([
        'status' => InventoryCorrectionStatus::Posted,
        'posted_at' => now(),
    ])->save();
    expect(fn () => $service->removeLine($postedLine))
        ->toThrow(DomainException::class, 'draft correction');

    $cancelled = InventoryCorrection::factory()->cancelled()->create();
    expect(fn (): InventoryCorrection => $service->post($cancelled, $actor))
        ->toThrow(DomainException::class, 'cancelled inventory correction');

    expect(fn (): mixed => correctionCoverageInvoke('lockedDraft', $posted))
        ->toThrow(DomainException::class, 'while the correction is a draft');

    $deliveryA = InventoryOperation::factory()->delivery()->done()->create();
    $deliveryB = InventoryOperation::factory()->delivery()->done()->create();
    $variant = ProductVariant::factory()->create();
    $deliveryLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $deliveryB->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
    ]);
    $mismatchedDeliveryCorrection = InventoryCorrection::factory()->create([
        'correction_type' => InventoryCorrectionType::Delivery,
        'original_inventory_operation_id' => $deliveryA->getKey(),
    ]);
    expect(fn () => $service->addDeliveryLine($mismatchedDeliveryCorrection, $deliveryLine, '1.000000'))
        ->toThrow(DomainException::class, 'original completed delivery');

    $transferA = InventoryOperation::factory()->internalTransfer()->done()->create();
    $transferB = InventoryOperation::factory()->internalTransfer()->done()->create();
    $transferLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $transferB->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
    ]);
    $mismatchedTransferCorrection = InventoryCorrection::factory()->create([
        'correction_type' => InventoryCorrectionType::Transfer,
        'original_inventory_operation_id' => $transferA->getKey(),
    ]);
    expect(fn () => $service->addTransferLine($mismatchedTransferCorrection, $transferLine, '1.000000'))
        ->toThrow(DomainException::class, 'original completed internal transfer');
});
