<?php

declare(strict_types=1);

use App\Enums\InventoryReturnDisposition;
use App\Enums\InventoryReturnStatus;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryReturnService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function returnCoverageInvoke(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(InventoryReturnService::class, $method)
        ->invoke(app(InventoryReturnService::class), ...$arguments);
}
it('covers return creation warehouse and purchase order guards', function (): void {
    $service = app(InventoryReturnService::class);
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $customer->getKey(),
    ]);
    $inactive = Warehouse::factory()->create(['is_active' => false]);

    expect(fn () => $service->createCustomerReturn($actor, $delivery, $inactive))
        ->toThrow(DomainException::class, 'active warehouse');

    $supplier = Supplier::factory()->create();
    $warehouse = Warehouse::factory()->create();

    expect(fn () => $service->createSupplierReturn($actor, $supplier, $warehouse, null, 0))
        ->toThrow(DomainException::class, 'positive identifier')
        ->and(fn () => $service->createSupplierReturn($actor, $supplier, $warehouse, null, 999999))
        ->toThrow(DomainException::class, 'does not exist');
});
it('covers draft ready post cancel and line mutation guards', function (): void {
    $service = app(InventoryReturnService::class);
    $actor = User::factory()->create();

    $emptyDraft = InventoryReturn::factory()->customer()->create();
    expect(fn () => $service->markReady($emptyDraft, $actor))
        ->toThrow(DomainException::class, 'at least one line');

    $ready = InventoryReturn::factory()->customer()->ready()->create();
    expect(fn () => $service->markReady($ready, $actor))
        ->toThrow(DomainException::class, 'Only a draft return')
        ->and(fn () => $service->post($emptyDraft, $actor))
        ->toThrow(DomainException::class, 'Only a ready return');

    $lineReturn = InventoryReturn::factory()->customer()->create();
    $line = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $lineReturn->getKey(),
    ]);
    InventoryReturn::withoutEvents(function () use ($lineReturn): void {
        $lineReturn->forceFill([
            'status' => InventoryReturnStatus::Ready,
            'ready_at' => now(),
        ])->save();
    });

    $readyWithoutLines = InventoryReturn::factory()->customer()->ready()->create();
    expect(fn () => $service->post($readyWithoutLines, $actor))
        ->toThrow(DomainException::class, 'at least one line');

    $posted = InventoryReturn::factory()->customer()->posted()->create();
    expect(fn () => $service->cancel($posted, $actor))
        ->toThrow(DomainException::class, 'cannot be cancelled');

    expect(fn () => $service->removeLine($line))
        ->toThrow(DomainException::class, 'only be removed while');
});
it('requires inspection and supplier source condition before ready', function (): void {
    $service = app(InventoryReturnService::class);
    $actor = User::factory()->create();

    $customerReturn = InventoryReturn::factory()->customer()->create();
    InventoryReturnLine::factory()->create([
        'inventory_return_id' => $customerReturn->getKey(),
        'disposition' => null,
        'inspected_at' => null,
    ]);
    expect(fn () => $service->markReady($customerReturn, $actor))
        ->toThrow(DomainException::class, 'must be inspected');

    $supplierReturn = InventoryReturn::factory()->supplier()->create([
        'supplier_id' => Supplier::factory(),
    ]);
    InventoryReturnLine::factory()->create([
        'inventory_return_id' => $supplierReturn->getKey(),
        'source_condition' => null,
    ]);
    expect(fn () => $service->markReady($supplierReturn, $actor))
        ->toThrow(DomainException::class, 'requires a source stock condition');
});
it('covers supplier line type and disposed-condition guards', function (): void {
    $service = app(InventoryReturnService::class);
    $actor = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    $supplierReturn = $service->createSupplierReturn($actor, $supplier, $warehouse);
    expect(fn () => $service->addSupplierLine(
        $supplierReturn,
        $variant,
        (int) $variant->unit_id,
        '1',
        StockCondition::Disposed,
    ))->toThrow(DomainException::class, 'materialized warehouse source condition');

    $customerReturn = InventoryReturn::factory()->customer()->create();
    expect(fn () => $service->addSupplierLine(
        $customerReturn,
        $variant,
        (int) $variant->unit_id,
        '1',
        StockCondition::Saleable,
    ))->toThrow(DomainException::class, 'matching type');
});
it('covers return quantity and snapshot validation helpers', function (): void {
    $variant = ProductVariant::factory()->create();
    $line = InventoryReturnLine::factory()->make([
        'product_variant_id' => $variant->getKey(),
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
    ]);

    expect(fn (): mixed => returnCoverageInvoke('positiveDecimal', '0'))
        ->toThrow(DomainException::class, 'positive exact decimals')
        ->and(fn (): mixed => returnCoverageInvoke('positiveDecimal', '1.1234567'))
        ->toThrow(DomainException::class, 'positive exact decimals')
        ->and(fn (): mixed => returnCoverageInvoke('decimal', 'not-numeric'))
        ->toThrow(DomainException::class, 'must be numeric');

    $operationLine = new InventoryOperationLine([
        'conversion_factor_snapshot' => '0.000000',
        'transaction_unit_id' => (int) $variant->unit_id,
    ]);
    expect(fn (): mixed => returnCoverageInvoke('customerReturnSnapshot', $operationLine, '1'))
        ->toThrow(DomainException::class, 'complete UOM snapshot');
});
it('covers exact reversal provenance guards and cross warehouse behavior', function (): void {
    $warehouseA = Warehouse::factory()->create();
    $warehouseB = Warehouse::factory()->create();
    $variantA = ProductVariant::factory()->create();
    $variantB = ProductVariant::factory()->create();
    $return = InventoryReturn::factory()->create(['warehouse_id' => $warehouseA->getKey()]);

    $missing = InventoryReturnLine::factory()->make([
        'inventory_return_id' => $return->getKey(),
        'product_variant_id' => $variantA->getKey(),
        'original_inventory_movement_id' => 999999,
    ]);
    expect(fn (): mixed => returnCoverageInvoke('exactReversalMovementId', $return, $missing))
        ->toThrow(DomainException::class, 'no longer exists');

    $movement = InventoryMovement::factory()->create([
        'product_variant_id' => $variantA->getKey(),
        'warehouse_id' => $warehouseA->getKey(),
        'movement_type' => MovementType::Receipt,
    ]);
    $wrongVariant = InventoryReturnLine::factory()->make([
        'inventory_return_id' => $return->getKey(),
        'product_variant_id' => $variantB->getKey(),
        'original_inventory_movement_id' => $movement->getKey(),
    ]);
    expect(fn (): mixed => returnCoverageInvoke('exactReversalMovementId', $return, $wrongVariant))
        ->toThrow(DomainException::class, 'no longer matches');

    InventoryMovement::withoutEvents(function () use ($movement, $warehouseB): void {
        $movement->forceFill(['warehouse_id' => $warehouseB->getKey()])->save();
    });
    $crossWarehouse = InventoryReturnLine::factory()->make([
        'inventory_return_id' => $return->getKey(),
        'product_variant_id' => $variantA->getKey(),
        'original_inventory_movement_id' => $movement->getKey(),
    ]);
    expect(returnCoverageInvoke('exactReversalMovementId', $return, $crossWarehouse))->toBeNull();
});
it('covers customer allocation tracking guards', function (): void {
    $return = InventoryReturn::factory()->customer()->create();

    $missingVariantLine = new InventoryOperationLine;
    $missingVariantLine->setRelation('productVariant', null);

    expect(fn (): mixed => returnCoverageInvoke(
        'assertCustomerAllocation',
        $return,
        $missingVariantLine,
        '1.000000',
        null,
        null,
    ))->toThrow(DomainException::class, 'variant no longer exists');

    $grain = ProductVariant::factory()->grain()->create();
    $grainLine = new InventoryOperationLine;
    $grainLine->forceFill(['product_variant_id' => $grain->getKey()]);
    $grainLine->setRelation('productVariant', $grain);

    expect(fn (): mixed => returnCoverageInvoke(
        'assertCustomerAllocation',
        $return,
        $grainLine,
        '1.000000',
        null,
        null,
    ))->toThrow(DomainException::class, 'lot allocation is required');

    $machine = ProductVariant::factory()->machine()->create();
    $machineLine = new InventoryOperationLine;
    $machineLine->forceFill(['product_variant_id' => $machine->getKey()]);
    $machineLine->setRelation('productVariant', $machine);

    expect(fn (): mixed => returnCoverageInvoke(
        'assertCustomerAllocation',
        $return,
        $machineLine,
        '1.000000',
        999,
        null,
    ))->toThrow(DomainException::class, 'lot allocation is not valid');

    $grainSerialLine = new InventoryOperationLine;
    $grainSerialLine->forceFill([
        'product_variant_id' => $grain->getKey(),
        'inventory_lot_id' => 123,
    ]);
    $grainSerialLine->setRelation('productVariant', $grain);

    expect(fn (): mixed => returnCoverageInvoke(
        'assertCustomerAllocation',
        $return,
        $grainSerialLine,
        '1.000000',
        123,
        999,
    ))->toThrow(DomainException::class, 'serial allocation is not valid');
    expect(fn (): mixed => returnCoverageInvoke(
        'assertCustomerAllocation',
        $return,
        $machineLine,
        '2.000000',
        null,
        null,
    ))->toThrow(DomainException::class, 'exactly one delivered serial');
});
it('covers duplicate serialized customer return guard', function (): void {
    $machine = ProductVariant::factory()->machine()->create();
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $machine->getKey(),
        'status' => SerializedInventoryUnitStatus::Delivered,
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->getKey(),
        'warehouse_id' => null,
    ]);

    $previous = InventoryReturn::factory()->customer()->create(['customer_id' => $customer->getKey()]);
    InventoryReturnLine::factory()->create([
        'inventory_return_id' => $previous->getKey(),
        'product_variant_id' => $machine->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);
    InventoryReturn::withoutEvents(function () use ($previous): void {
        $previous->forceFill([
            'status' => InventoryReturnStatus::Posted,
            'posted_at' => now(),
        ])->save();
    });

    $current = InventoryReturn::factory()->customer()->create(['customer_id' => $customer->getKey()]);
    $deliveryLine = new InventoryOperationLine;
    $deliveryLine->forceFill([
        'product_variant_id' => $machine->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);
    $deliveryLine->setRelation('productVariant', $machine);

    expect(fn (): mixed => returnCoverageInvoke(
        'assertCustomerAllocation',
        $current,
        $deliveryLine,
        '1.000000',
        null,
        (int) $unit->getKey(),
    ))->toThrow(DomainException::class, 'already been returned');
});
it('covers supplier allocation serial and nontracked guards', function (): void {
    $supplier = Supplier::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $return = InventoryReturn::factory()->supplier()->create([
        'supplier_id' => $supplier->getKey(),
        'warehouse_id' => $warehouse->getKey(),
    ]);

    $machine = ProductVariant::factory()->machine()->create();
    expect(fn (): mixed => returnCoverageInvoke(
        'assertSupplierAllocation',
        $return,
        $machine,
        '1.000000',
        StockCondition::Saleable,
        999,
        null,
    ))->toThrow(DomainException::class, 'lot is not valid');

    $grain = ProductVariant::factory()->grain()->create();
    $lot = InventoryLot::factory()->canonical()->for($grain, 'productVariant')->create([
        'lot_number' => 'RETURN-SERIAL-GUARD',
        'expires_at' => null,
    ]);
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '2.000000',
        'reserved_base_quantity' => '0.000000',
    ]);
    expect(fn (): mixed => returnCoverageInvoke(
        'assertSupplierAllocation',
        $return,
        $grain,
        '1.000000',
        StockCondition::Saleable,
        (int) $lot->getKey(),
        999,
    ))->toThrow(DomainException::class, 'serial is not valid');
    expect(fn (): mixed => returnCoverageInvoke(
        'assertSupplierAllocation',
        $return,
        $machine,
        '2.000000',
        StockCondition::Saleable,
        null,
        null,
    ))->toThrow(DomainException::class, 'exactly one serial')
        ->and(fn (): mixed => returnCoverageInvoke(
            'assertSupplierAllocation',
            $return,
            $machine,
            '1.000000',
            StockCondition::Damaged,
            null,
            999999,
        ))->toThrow(DomainException::class, 'not eligible');
});
it('covers customer posting command evidence disposition and remaining guards', function (): void {
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->create();

    $wrongOperation = InventoryOperation::factory()->receipt()->done()->create();
    $wrongLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $wrongOperation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'base_quantity' => '2.000000',
    ]);
    $return = InventoryReturn::factory()->customer()->create([
        'original_inventory_operation_id' => $wrongOperation->getKey(),
    ]);
    $returnLine = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $return->getKey(),
        'product_variant_id' => $variant->getKey(),
        'original_inventory_operation_line_id' => $wrongLine->getKey(),
        'base_quantity' => '1.000000',
    ]);

    expect(fn (): mixed => returnCoverageInvoke(
        'customerPostingCommands',
        $return,
        new Collection([$returnLine]),
        $actor,
    ))->toThrow(DomainException::class, 'delivery evidence is no longer valid');

    $delivery = InventoryOperation::factory()->delivery()->done()->create();
    $deliveryLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $delivery->getKey(),
        'product_variant_id' => $variant->getKey(),
        'base_quantity' => '2.000000',
    ]);
    $validReturn = InventoryReturn::factory()->customer()->create([
        'original_inventory_operation_id' => $delivery->getKey(),
    ]);
    $uninspected = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $validReturn->getKey(),
        'product_variant_id' => $variant->getKey(),
        'original_inventory_operation_line_id' => $deliveryLine->getKey(),
        'base_quantity' => '1.000000',
        'disposition' => null,
        'inspected_at' => null,
    ]);
    expect(fn (): mixed => returnCoverageInvoke(
        'customerPostingCommands',
        $validReturn,
        new Collection([$uninspected]),
        $actor,
    ))->toThrow(DomainException::class, 'inspected disposition');

    $tooMuch = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $validReturn->getKey(),
        'product_variant_id' => $variant->getKey(),
        'original_inventory_operation_line_id' => $deliveryLine->getKey(),
        'base_quantity' => '2.000000',
        'disposition' => InventoryReturnDisposition::Saleable,
        'inspected_at' => now(),
    ]);
    expect(fn (): mixed => returnCoverageInvoke(
        'customerPostingCommands',
        $validReturn,
        new Collection([$uninspected, $tooMuch]),
        $actor,
    ))->toThrow(DomainException::class, 'exceed the remaining delivered quantity');
});
it('covers supplier posting command provenance condition and remaining guards', function (): void {
    $actor = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    $supplierReturn = InventoryReturn::factory()->supplier()->create([
        'supplier_id' => $supplier->getKey(),
        'warehouse_id' => $warehouse->getKey(),
    ]);
    $noCondition = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $supplierReturn->getKey(),
        'product_variant_id' => $variant->getKey(),
        'source_condition' => null,
    ]);
    expect(fn (): mixed => returnCoverageInvoke(
        'supplierPostingCommands',
        $supplierReturn,
        new Collection([$noCondition]),
        $actor,
    ))->toThrow(DomainException::class, 'materialized source condition');

    $delivery = InventoryOperation::factory()->delivery()->done()->create();
    $deliveryLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $delivery->getKey(),
        'product_variant_id' => $variant->getKey(),
        'base_quantity' => '1.000000',
    ]);
    $badProvenance = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $supplierReturn->getKey(),
        'product_variant_id' => $variant->getKey(),
        'source_condition' => StockCondition::Saleable,
        'original_inventory_operation_line_id' => $deliveryLine->getKey(),
    ]);
    expect(fn (): mixed => returnCoverageInvoke(
        'supplierPostingCommands',
        $supplierReturn,
        new Collection([$badProvenance]),
        $actor,
    ))->toThrow(DomainException::class, 'receipt provenance is no longer valid');

    $receipt = InventoryOperation::factory()->receipt()->done()->create([
        'supplier_id' => $supplier->getKey(),
    ]);
    $receiptLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $receipt->getKey(),
        'product_variant_id' => $variant->getKey(),
        'base_quantity' => '1.000000',
    ]);
    $returnWithReceipt = InventoryReturn::factory()->supplier()->create([
        'supplier_id' => $supplier->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'original_inventory_operation_id' => $receipt->getKey(),
    ]);
    $over = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $returnWithReceipt->getKey(),
        'product_variant_id' => $variant->getKey(),
        'source_condition' => StockCondition::Saleable,
        'original_inventory_operation_line_id' => $receiptLine->getKey(),
        'base_quantity' => '2.000000',
    ]);
    expect(fn (): mixed => returnCoverageInvoke(
        'supplierPostingCommands',
        $returnWithReceipt,
        new Collection([$over]),
        $actor,
    ))->toThrow(DomainException::class, 'exceed the remaining quantity');
});

it('covers unsaved return identifiers and provenance guards', function (): void {
    $service = app(InventoryReturnService::class);
    $actor = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $customer = CustomerProfile::factory()->create();

    expect(fn (): InventoryReturn => $service->createCustomerReturn(
        $actor,
        new InventoryOperation,
        $warehouse,
    ))->toThrow(LogicException::class, 'Inventory return identifiers must be integers.');

    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $customer->getKey(),
    ]);
    expect(fn (): InventoryReturn => $service->createCustomerReturn(
        $actor,
        $delivery,
        new Warehouse,
    ))->toThrow(LogicException::class, 'Inventory return identifiers must be integers.');

    $supplier = Supplier::factory()->create();
    expect(fn (): InventoryReturn => $service->createSupplierReturn(
        $actor,
        new Supplier,
        $warehouse,
    ))->toThrow(LogicException::class, 'Inventory return identifiers must be integers.');

    expect(fn (): InventoryReturn => $service->createSupplierReturn(
        $actor,
        $supplier,
        new Warehouse,
    ))->toThrow(LogicException::class, 'Inventory return identifiers must be integers.');

    expect(fn (): InventoryReturn => $service->createSupplierReturn(
        $actor,
        $supplier,
        $warehouse,
        new InventoryOperation,
    ))->toThrow(LogicException::class, 'Inventory return identifiers must be integers.');

    $customerReturn = InventoryReturn::factory()->customer()->create();
    expect(fn (): InventoryReturnLine => $service->addCustomerLine(
        $customerReturn,
        new InventoryOperationLine,
        '1',
    ))->toThrow(LogicException::class, 'Inventory return identifiers must be integers.');

    $supplierReturn = InventoryReturn::factory()->supplier()->create([
        'supplier_id' => $supplier->getKey(),
        'warehouse_id' => $warehouse->getKey(),
    ]);
    expect(fn (): InventoryReturnLine => $service->addSupplierLine(
        $supplierReturn,
        new ProductVariant,
        1,
        '1',
        StockCondition::Saleable,
    ))->toThrow(LogicException::class, 'Inventory return identifiers must be integers.');

    $variant = ProductVariant::factory()->create();
    expect(fn (): InventoryReturnLine => $service->addSupplierLine(
        $supplierReturn,
        $variant,
        (int) $variant->unit_id,
        '1',
        StockCondition::Saleable,
        receiptLine: new InventoryOperationLine,
    ))->toThrow(LogicException::class, 'Inventory return identifiers must be integers.');

    expect(fn (): InventoryReturnLine => $service->inspectLine(
        new InventoryReturnLine,
        InventoryReturnDisposition::Saleable,
        $actor,
    ))->toThrow(LogicException::class, 'Inventory return identifiers must be integers.');
});
