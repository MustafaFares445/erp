<?php

declare(strict_types=1);

use App\Enums\InventoryCorrectionType;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryCorrection;
use App\Models\InventoryCorrectionLine;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCorrectionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function batch71Invoke(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(InventoryCorrectionService::class, $method)
        ->invoke(app(InventoryCorrectionService::class), ...$arguments);
}

it('builds an unknown-custody serial target when reversing a receipt with no supplier', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
        'custody_reference_type' => 'warehouse',
        'custody_reference_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'inventory_lot_id' => null,
    ]);
    $operation = InventoryOperation::factory()->receipt()->done()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => null,
    ]);
    $operationLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
    ]);
    $movement = InventoryMovement::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => MovementType::Receipt,
        'quantity' => '1.000000',
        'base_quantity_delta' => '1.000000',
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
        'source_line_type' => 'inventory_operation_line',
        'source_line_id' => $operationLine->getKey(),
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'serialized_inventory_unit_id' => $unit->getKey(),
        'inventory_lot_id' => null,
        'stock_condition_to' => StockCondition::Saleable,
    ]);
    $correction = InventoryCorrection::factory()->create([
        'correction_type' => InventoryCorrectionType::Receipt,
        'original_inventory_operation_id' => $operation->getKey(),
        'reason' => 'Receipt had no supplier provenance.',
    ]);
    $line = InventoryCorrectionLine::factory()->create([
        'inventory_correction_id' => $correction->getKey(),
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'inventory_lot_id' => null,
        'original_inventory_operation_line_id' => $operationLine->getKey(),
        'original_inventory_movement_id' => $movement->getKey(),
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
    ]);

    $commands = batch71Invoke(
        'receiptPostingCommands',
        $correction,
        new Collection([$line]),
        $operation,
        (int) User::factory()->create()->getKey(),
    );

    expect($commands)->toHaveCount(1)
        ->and($commands[0]->serializedTargetStatus)->toBe(SerializedInventoryUnitStatus::Unknown)
        ->and($commands[0]->serializedTargetCustodyType)->toBe(SerializedCustodyType::Unknown)
        ->and($commands[0]->serializedTargetCustodyReferenceType)->toBe('inventory_correction')
        ->and($commands[0]->serializedTargetCustodyReferenceId)->toBe($correction->getKey());
});

it('rejects a transfer correction whose target equals the warehouse being corrected', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->internalTransfer()->done()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $operationLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
    ]);
    $movement = InventoryMovement::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $destination->getKey(),
        'movement_type' => MovementType::Transfer,
        'quantity' => '2.000000',
        'base_quantity_delta' => '2.000000',
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
        'source_line_type' => 'inventory_operation_line',
        'source_line_id' => $operationLine->getKey(),
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'stock_condition_to' => StockCondition::Saleable,
    ]);
    $correction = InventoryCorrection::factory()->create([
        'correction_type' => InventoryCorrectionType::Transfer,
        'original_inventory_operation_id' => $operation->getKey(),
        'target_warehouse_id' => $destination->getKey(),
        'reason' => 'Invalid same target.',
    ]);
    $line = InventoryCorrectionLine::factory()->create([
        'inventory_correction_id' => $correction->getKey(),
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $destination->getKey(),
        'original_inventory_operation_line_id' => $operationLine->getKey(),
        'original_inventory_movement_id' => $movement->getKey(),
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
        'inventory_lot_id' => null,
        'serialized_inventory_unit_id' => null,
    ]);

    expect(fn (): mixed => batch71Invoke(
        'transferPostingCommands',
        $correction,
        new Collection([$line]),
        $operation,
        (int) User::factory()->create()->getKey(),
    ))->toThrow(DomainException::class, 'target warehouse must differ');
});

it('builds non-lot non-serialized transfer correction postings with null lot deltas', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->internalTransfer()->done()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $operationLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
    ]);
    $movement = InventoryMovement::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $destination->getKey(),
        'movement_type' => MovementType::Transfer,
        'quantity' => '2.000000',
        'base_quantity_delta' => '2.000000',
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
        'source_line_type' => 'inventory_operation_line',
        'source_line_id' => $operationLine->getKey(),
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'stock_condition_to' => StockCondition::Saleable,
        'inventory_lot_id' => null,
        'serialized_inventory_unit_id' => null,
    ]);
    $correction = InventoryCorrection::factory()->create([
        'correction_type' => InventoryCorrectionType::Transfer,
        'original_inventory_operation_id' => $operation->getKey(),
        'target_warehouse_id' => $source->getKey(),
        'reason' => 'Return stock to the original source.',
    ]);
    $line = InventoryCorrectionLine::factory()->create([
        'inventory_correction_id' => $correction->getKey(),
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $destination->getKey(),
        'original_inventory_operation_line_id' => $operationLine->getKey(),
        'original_inventory_movement_id' => $movement->getKey(),
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
        'inventory_lot_id' => null,
        'serialized_inventory_unit_id' => null,
    ]);

    $commands = batch71Invoke(
        'transferPostingCommands',
        $correction,
        new Collection([$line]),
        $operation,
        (int) User::factory()->create()->getKey(),
    );

    expect($commands)->toHaveCount(2)
        ->and($commands[0]->lotOnHandBaseQuantityDelta)->toBeNull()
        ->and($commands[1]->lotOnHandBaseQuantityDelta)->toBeNull()
        ->and($commands[1]->serializedTargetInventoryLotId)->toBeNull();
});
