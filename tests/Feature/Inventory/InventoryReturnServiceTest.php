<?php

declare(strict_types=1);

use App\Enums\InventoryReturnDisposition;
use App\Enums\InventoryReturnStatus;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\Bill;
use App\Models\CreditNote;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Refund;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('posts customer return dispositions into saleable quarantine and damaged condition balances', function (
    InventoryReturnDisposition $disposition,
    float $expectedSaleable,
    float $expectedQuarantine,
    float $expectedDamaged,
    float $expectedAvailable,
    float $expectedLotCondition,
): void {
    [$delivery, $deliveryLine, $warehouse, $variant, $lot, $actor] = completedCustomerGrainDelivery(
        startingQuantity: '10.000000',
        deliveredQuantity: '4.000000',
    );

    $service = app(InventoryReturnService::class);
    $return = $service->createCustomerReturn($actor, $delivery, $warehouse, 'Customer return');
    $line = $service->addCustomerLine(
        $return,
        $deliveryLine,
        '2.000000',
        (int) $lot->getKey(),
    );
    $service->inspectLine($line, $disposition, $actor, 'Inspected');
    $service->markReady($return, $actor);
    $posted = $service->post($return->refresh(), $actor);

    $stock = InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->sole();

    expect($posted->status)->toBe(InventoryReturnStatus::Posted)
        ->and((float) $stock->on_hand_quantity)->toBe(8.0)
        ->and((float) $stock->available_quantity)->toBe($expectedAvailable)
        ->and(returnConditionOnHand($variant, $warehouse, StockCondition::Saleable))->toBe($expectedSaleable)
        ->and(returnConditionOnHand($variant, $warehouse, StockCondition::Quarantine))->toBe($expectedQuarantine)
        ->and(returnConditionOnHand($variant, $warehouse, StockCondition::Damaged))->toBe($expectedDamaged)
        ->and(returnLotConditionOnHand($lot, $warehouse, $disposition->stockCondition()))->toBe($expectedLotCondition)
        ->and($line->refresh()->posted_base_quantity)->toBe('2.000000');

    $movement = InventoryMovement::query()
        ->where('source_type', 'inventory_return')
        ->where('source_id', $return->getKey())
        ->where('source_line_type', 'inventory_return_line')
        ->where('source_line_id', $line->getKey())
        ->sole();

    expect($movement->movement_type)->toBe(MovementType::Return)
        ->and($movement->quantity)->toBe('2.000000')
        ->and($movement->inventory_lot_id)->toBe($lot->getKey())
        ->and($line->refresh()->posted_inventory_movement_id)->toBe($movement->getKey());
})->with([
    'saleable' => [InventoryReturnDisposition::Saleable, 8.0, 0.0, 0.0, 8.0, 8.0],
    'quarantine' => [InventoryReturnDisposition::Quarantine, 6.0, 2.0, 0.0, 6.0, 2.0],
    'damaged' => [InventoryReturnDisposition::Damaged, 6.0, 0.0, 2.0, 6.0, 2.0],
]);

it('rejects an over-return after a prior partial customer return is posted', function (): void {
    [$delivery, $deliveryLine, $warehouse, , $lot, $actor] = completedCustomerGrainDelivery(
        startingQuantity: '10.000000',
        deliveredQuantity: '4.000000',
    );

    $service = app(InventoryReturnService::class);
    $first = $service->createCustomerReturn($actor, $delivery, $warehouse);
    $firstLine = $service->addCustomerLine($first, $deliveryLine, '3.000000', (int) $lot->getKey());
    $service->inspectLine($firstLine, InventoryReturnDisposition::Saleable, $actor);
    $service->markReady($first, $actor);
    $service->post($first->refresh(), $actor);

    $second = $service->createCustomerReturn($actor, $delivery, $warehouse);

    expect(fn () => $service->addCustomerLine(
        $second,
        $deliveryLine,
        '2.000000',
        (int) $lot->getKey(),
    ))->toThrow(
        DomainException::class,
        'exceeds the quantity still returnable',
    );
});

it('rejects a customer return lot that was not the delivered allocation', function (): void {
    [$delivery, $deliveryLine, $warehouse, $variant, , $actor] = completedCustomerGrainDelivery();
    $wrongLot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '1.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);

    $return = app(InventoryReturnService::class)->createCustomerReturn($actor, $delivery, $warehouse);

    expect(fn () => app(InventoryReturnService::class)->addCustomerLine(
        $return,
        $deliveryLine,
        '1.000000',
        (int) $wrongLot->getKey(),
    ))->toThrow(DomainException::class, 'must match the lot originally delivered');
});

it('returns a delivered serial to warehouse custody and prevents returning it twice', function (): void {
    [$delivery, $line, $warehouse, $unit, $actor] = completedCustomerMachineDelivery();

    $service = app(InventoryReturnService::class);
    $return = $service->createCustomerReturn($actor, $delivery, $warehouse);
    $returnLine = $service->addCustomerLine(
        $return,
        $line,
        '1',
        null,
        (int) $unit->getKey(),
    );
    $service->inspectLine($returnLine, InventoryReturnDisposition::Quarantine, $actor);
    $service->markReady($return, $actor);
    $service->post($return->refresh(), $actor);

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::Available)
        ->and($unit->warehouse_id)->toBe($warehouse->getKey())
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Warehouse)
        ->and($unit->stock_condition)->toBe(StockCondition::Quarantine);

    $duplicate = $service->createCustomerReturn($actor, $delivery, $warehouse);

    expect(fn () => $service->addCustomerLine(
        $duplicate,
        $line,
        '1',
        null,
        (int) $unit->getKey(),
    ))->toThrow(DomainException::class, 'not currently held by the customer');
});

it('posts a saleable supplier return out of the exact lot balance', function (): void {
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);

    $service = app(InventoryReturnService::class);
    $return = $service->createSupplierReturn($actor, $supplier, $warehouse);
    $line = $service->addSupplierLine(
        $return,
        $variant,
        (int) $variant->unit_id,
        '2.000000',
        StockCondition::Saleable,
        (int) $lot->getKey(),
    );
    $service->markReady($return, $actor);
    $service->post($return->refresh(), $actor);

    expect($stock->refresh()->on_hand_quantity)->toBe('3.000000')
        ->and(returnLotConditionOnHand($lot, $warehouse, StockCondition::Saleable))->toBe(3.0)
        ->and($line->refresh()->posted_base_quantity)->toBe('2.000000');

    $movement = InventoryMovement::query()
        ->where('source_type', 'inventory_return')
        ->where('source_id', $return->getKey())
        ->sole();

    expect($movement->movement_type)->toBe(MovementType::Return)
        ->and($movement->quantity)->toBe('-2.000000');
});

it('moves a serialized supplier return into supplier custody', function (): void {
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $actor = User::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '1.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '1.000000',
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Saleable,
    ]);

    $service = app(InventoryReturnService::class);
    $return = $service->createSupplierReturn($actor, $supplier, $warehouse);
    $service->addSupplierLine(
        $return,
        $variant,
        (int) $variant->unit_id,
        '1',
        StockCondition::Saleable,
        null,
        (int) $unit->getKey(),
    );
    $service->markReady($return, $actor);
    $service->post($return->refresh(), $actor);

    expect($stock->refresh()->on_hand_quantity)->toBe('0.000000')
        ->and($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::ReturnedToSupplier)
        ->and($unit->warehouse_id)->toBeNull()
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Supplier)
        ->and($unit->custody_reference_type)->toBe('supplier')
        ->and($unit->custody_reference_id)->toBe($supplier->getKey());
});

it('does not create financial documents when an inventory return posts', function (): void {
    [$delivery, $deliveryLine, $warehouse, , $lot, $actor] = completedCustomerGrainDelivery();

    $before = financialDocumentCounts();

    $service = app(InventoryReturnService::class);
    $return = $service->createCustomerReturn($actor, $delivery, $warehouse);
    $line = $service->addCustomerLine(
        $return,
        $deliveryLine,
        '1.000000',
        (int) $lot->getKey(),
    );
    $service->inspectLine($line, InventoryReturnDisposition::Saleable, $actor);
    $service->markReady($return, $actor);
    $service->post($return->refresh(), $actor);

    expect(financialDocumentCounts())->toBe($before);
});

/**
 * @return array{InventoryOperation, InventoryOperationLine, Warehouse, ProductVariant, InventoryLot, User}
 */
function completedCustomerGrainDelivery(
    string $startingQuantity = '10.000000',
    string $deliveredQuantity = '4.000000',
): array {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => $startingQuantity,
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => $startingQuantity,
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => $startingQuantity,
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $line = $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => $deliveredQuantity,
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($delivery, $actor);
    $operations->complete($delivery->refresh(), $actor);

    return [$delivery->refresh(), $line->refresh(), $warehouse, $variant, $lot, $actor];
}

/**
 * @return array{InventoryOperation, InventoryOperationLine, Warehouse, SerializedInventoryUnit, User}
 */
function completedCustomerMachineDelivery(): array
{
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $actor = User::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '1.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '1.000000',
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Saleable,
    ]);
    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $line = $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($delivery, $actor);
    $operations->complete($delivery->refresh(), $actor);

    return [$delivery->refresh(), $line->refresh(), $warehouse, $unit, $actor];
}

function returnConditionOnHand(
    ProductVariant $variant,
    Warehouse $warehouse,
    StockCondition $condition,
): float {
    return (float) InventoryConditionBalance::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->where('stock_condition', $condition->value)
        ->value('on_hand_base_quantity');
}

function returnLotConditionOnHand(
    InventoryLot $lot,
    Warehouse $warehouse,
    StockCondition $condition,
): float {
    return (float) InventoryLotBalance::query()
        ->where('inventory_lot_id', $lot->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->where('stock_condition', $condition->value)
        ->value('on_hand_base_quantity');
}

/** @return array<string, int> */
function financialDocumentCounts(): array
{
    return [
        'credit_notes' => CreditNote::query()->count(),
        'refunds' => Refund::query()->count(),
        'journal_entries' => JournalEntry::query()->count(),
        'payments' => Payment::query()->count(),
        'bills' => Bill::query()->count(),
        'supplier_payments' => SupplierPayment::query()->count(),
    ];
}

it('posts supplier returns from quarantine and damaged conditions without making them saleable', function (
    StockCondition $condition,
): void {
    [$warehouse, $supplier, $variant, $lot, $stock, $actor] = supplierConditionFixture($condition, '3.000000');

    $service = app(InventoryReturnService::class);
    $return = $service->createSupplierReturn($actor, $supplier, $warehouse);
    $service->addSupplierLine(
        $return,
        $variant,
        (int) $variant->unit_id,
        '2.000000',
        $condition,
        (int) $lot->getKey(),
    );
    $service->markReady($return, $actor);
    $service->post($return->refresh(), $actor);

    expect($stock->refresh()->on_hand_quantity)->toBe('1.000000')
        ->and(returnConditionOnHand($variant, $warehouse, $condition))->toBe(1.0)
        ->and(returnConditionOnHand($variant, $warehouse, StockCondition::Saleable))->toBe(0.0)
        ->and(returnLotConditionOnHand($lot, $warehouse, $condition))->toBe(1.0);

    if ($condition === StockCondition::Damaged) {
        expect($stock->damaged_quantity)->toBe('1.000000');
    } else {
        expect($stock->damaged_quantity)->toBe('0.000000');
    }
})->with([
    'quarantine' => [StockCondition::Quarantine],
    'damaged' => [StockCondition::Damaged],
]);

it('caps supplier returns against referenced receipt provenance across return documents', function (): void {
    [$receipt, $receiptLine, $warehouse, $supplier, $variant, $lot, $actor] = completedSupplierGrainReceipt('4.000000');
    $service = app(InventoryReturnService::class);

    $first = $service->createSupplierReturn($actor, $supplier, $warehouse, $receipt);
    $service->addSupplierLine(
        $first,
        $variant,
        (int) $variant->unit_id,
        '3.000000',
        StockCondition::Saleable,
        (int) $lot->getKey(),
        null,
        $receiptLine,
    );
    $service->markReady($first, $actor);
    $service->post($first->refresh(), $actor);

    $second = $service->createSupplierReturn($actor, $supplier, $warehouse, $receipt);

    expect(fn () => $service->addSupplierLine(
        $second,
        $variant,
        (int) $variant->unit_id,
        '2.000000',
        StockCondition::Saleable,
        (int) $lot->getKey(),
        null,
        $receiptLine,
    ))->toThrow(
        DomainException::class,
        'exceeds the quantity still returnable from the referenced receipt line',
    );
});

it('freezes a ready return except for posting or cancellation', function (): void {
    [$delivery, $deliveryLine, $warehouse, , $lot, $actor] = completedCustomerGrainDelivery();
    $service = app(InventoryReturnService::class);
    $return = $service->createCustomerReturn($actor, $delivery, $warehouse);
    $line = $service->addCustomerLine($return, $deliveryLine, '1.000000', (int) $lot->getKey());
    $service->inspectLine($line, InventoryReturnDisposition::Saleable, $actor);
    $ready = $service->markReady($return, $actor);

    expect(fn () => $service->inspectLine(
        $line->refresh(),
        InventoryReturnDisposition::Damaged,
        $actor,
    ))->toThrow(DomainException::class, 'Only a draft customer return line can be inspected');

    expect(function () use ($ready): void {
        $ready->forceFill(['reason' => 'Changed after ready'])->save();
    })->toThrow(DomainException::class, 'ready inventory return is frozen');
});

it('keeps posted return headers and lines immutable', function (): void {
    [$delivery, $deliveryLine, $warehouse, , $lot, $actor] = completedCustomerGrainDelivery();
    $service = app(InventoryReturnService::class);
    $return = $service->createCustomerReturn($actor, $delivery, $warehouse);
    $line = $service->addCustomerLine($return, $deliveryLine, '1.000000', (int) $lot->getKey());
    $service->inspectLine($line, InventoryReturnDisposition::Saleable, $actor);
    $service->markReady($return, $actor);
    $posted = $service->post($return->refresh(), $actor);
    $postedLine = $line->refresh();

    expect(function () use ($posted): void {
        $posted->forceFill(['notes' => 'Rewrite history'])->save();
    })->toThrow(DomainException::class, 'immutable');

    expect(function () use ($postedLine): void {
        $postedLine->forceFill(['inspection_notes' => 'Rewrite inspection'])->save();
    })->toThrow(DomainException::class, 'immutable');

    expect(fn () => $posted->delete())
        ->toThrow(DomainException::class, 'cannot be deleted');
});

it('cancels an unposted return without creating a return movement', function (): void {
    [$delivery, $deliveryLine, $warehouse, , $lot, $actor] = completedCustomerGrainDelivery();
    $service = app(InventoryReturnService::class);
    $return = $service->createCustomerReturn($actor, $delivery, $warehouse);
    $service->addCustomerLine($return, $deliveryLine, '1.000000', (int) $lot->getKey());

    $cancelled = $service->cancel($return, $actor, 'Customer withdrew the return');

    expect($cancelled->status)->toBe(InventoryReturnStatus::Cancelled)
        ->and(InventoryMovement::query()
            ->where('source_type', 'inventory_return')
            ->where('source_id', $return->getKey())
            ->count())->toBe(0);
});

/**
 * @return array{Warehouse, Supplier, ProductVariant, InventoryLot, InventoryStock, User}
 */
function supplierConditionFixture(StockCondition $condition, string $quantity): array
{
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();
    $damaged = $condition === StockCondition::Damaged ? $quantity : '0.000000';

    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => $quantity,
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => $damaged,
        'available_quantity' => '0.000000',
    ]);

    InventoryConditionBalance::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->delete();

    foreach ([StockCondition::Saleable, StockCondition::Quarantine, StockCondition::Damaged] as $candidate) {
        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $candidate,
            'on_hand_base_quantity' => $candidate === $condition ? $quantity : '0.000000',
            'reserved_base_quantity' => '0.000000',
        ]);
    }

    $lot = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create([
        'lot_number' => 'SUP-'.mb_strtoupper($condition->value),
        'expires_at' => null,
    ]);

    foreach ([StockCondition::Saleable, StockCondition::Quarantine, StockCondition::Damaged] as $candidate) {
        InventoryLotBalance::query()->forceCreate([
            'inventory_lot_id' => $lot->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $candidate,
            'on_hand_base_quantity' => $candidate === $condition ? $quantity : '0.000000',
            'reserved_base_quantity' => '0.000000',
        ]);
    }

    return [$warehouse, $supplier, $variant, $lot, $stock, $actor];
}

/**
 * @return array{InventoryOperation, InventoryOperationLine, Warehouse, Supplier, ProductVariant, InventoryLot, User}
 */
function completedSupplierGrainReceipt(string $quantity): array
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
        'lot_number' => 'SUP-RECEIPT-LOT',
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($receipt, $actor);
    $operations->complete($receipt->refresh(), $actor);

    $line = $line->refresh();
    $lot = InventoryLot::query()->findOrFail($line->inventory_lot_id);

    return [$receipt->refresh(), $line, $warehouse, $supplier, $variant, $lot, $actor];
}

it('binds multi-line return movements to the correct return lines after posting sort', function (): void {
    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();

    $variantA = ProductVariant::factory()->grain()->create();
    $variantB = ProductVariant::factory()->grain()->create();

    InventoryStock::factory()->for($variantA)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);
    InventoryStock::factory()->for($variantB)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);

    $lotA = InventoryLot::factory()->for($variantA, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $lotB = InventoryLot::factory()->for($variantB, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);

    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $deliveryLineA = $delivery->lines()->create([
        'product_variant_id' => $variantA->getKey(),
        'quantity' => '2.000000',
        'unit_id' => $variantA->unit_id,
        'inventory_lot_id' => $lotA->getKey(),
    ]);
    $deliveryLineB = $delivery->lines()->create([
        'product_variant_id' => $variantB->getKey(),
        'quantity' => '2.000000',
        'unit_id' => $variantB->unit_id,
        'inventory_lot_id' => $lotB->getKey(),
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($delivery, $actor);
    $operations->complete($delivery->refresh(), $actor);

    $returns = app(InventoryReturnService::class);
    $return = $returns->createCustomerReturn($actor, $delivery->refresh(), $warehouse);

    // Deliberately add the higher variant id first. InventoryPostingService sorts
    // by variant/warehouse for locking, so result-array order differs from return-line id order.
    $returnLineB = $returns->addCustomerLine(
        $return,
        $deliveryLineB->refresh(),
        '1.000000',
        (int) $lotB->getKey(),
    );
    $returnLineA = $returns->addCustomerLine(
        $return,
        $deliveryLineA->refresh(),
        '1.000000',
        (int) $lotA->getKey(),
    );

    $returns->inspectLine($returnLineB, InventoryReturnDisposition::Saleable, $actor);
    $returns->inspectLine($returnLineA, InventoryReturnDisposition::Saleable, $actor);
    $returns->markReady($return, $actor);
    $returns->post($return->refresh(), $actor);

    foreach ([$returnLineA->refresh(), $returnLineB->refresh()] as $returnLine) {
        $movement = InventoryMovement::query()->findOrFail($returnLine->posted_inventory_movement_id);

        expect($movement->source_line_type)->toBe('inventory_return_line')
            ->and($movement->source_line_id)->toBe($returnLine->getKey())
            ->and($movement->product_variant_id)->toBe($returnLine->product_variant_id);
    }
});

it('rejects a supplier return purchase-order reference owned by another supplier', function (): void {
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $otherSupplier = Supplier::factory()->create();
    $actor = User::factory()->create();

    $purchaseOrderId = PurchaseOrder::factory()->create([
        'supplier_id' => $otherSupplier->getKey(),
        'destination_warehouse_id' => $warehouse->getKey(),
    ])->getKey();

    expect(fn () => app(InventoryReturnService::class)->createSupplierReturn(
        $actor,
        $supplier,
        $warehouse,
        null,
        $purchaseOrderId,
    ))->toThrow(
        DomainException::class,
        'belongs to a different supplier',
    );
});

it('rejects a supplier-return serial that differs from the referenced receipt line', function (): void {
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $actor = User::factory()->create();

    $receipt = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $supplier->getKey(),
    ]);
    $receivedUnit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'status' => SerializedInventoryUnitStatus::Pending,
    ]);
    $receiptLine = $receipt->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $receivedUnit->getKey(),
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($receipt, $actor);
    $operations->complete($receipt->refresh(), $actor);

    $otherUnit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Saleable,
    ]);

    $return = app(InventoryReturnService::class)->createSupplierReturn(
        $actor,
        $supplier,
        $warehouse,
        $receipt->refresh(),
    );

    expect(fn () => app(InventoryReturnService::class)->addSupplierLine(
        $return,
        $variant,
        (int) $variant->unit_id,
        '1',
        StockCondition::Saleable,
        null,
        (int) $otherUnit->getKey(),
        $receiptLine->refresh(),
    ))->toThrow(
        DomainException::class,
        'serial does not match the referenced receipt line',
    );
});

it('rejects a customer return against a cancelled delivery', function (): void {
    $warehouse = Warehouse::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->canceled()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);

    expect(fn () => app(InventoryReturnService::class)->createCustomerReturn(
        User::factory()->create(),
        $delivery,
        $warehouse,
    ))->toThrow(
        DomainException::class,
        'completed customer delivery',
    );
});

it('rejects a customer-return serial that differs from the delivered allocation', function (): void {
    [$delivery, $line, $warehouse, $deliveredUnit, $actor] = completedCustomerMachineDelivery();
    $wrongUnit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $deliveredUnit->product_variant_id,
        'warehouse_id' => null,
        'status' => SerializedInventoryUnitStatus::Delivered,
        'custody_type' => SerializedCustodyType::Customer,
        'stock_condition' => StockCondition::Saleable,
    ]);

    $return = app(InventoryReturnService::class)->createCustomerReturn(
        $actor,
        $delivery,
        $warehouse,
    );

    expect(fn () => app(InventoryReturnService::class)->addCustomerLine(
        $return,
        $line,
        '1',
        null,
        (int) $wrongUnit->getKey(),
    ))->toThrow(
        DomainException::class,
        'must match the serial originally delivered',
    );
});

it('rejects a supplier return when the selected saleable lot quantity is reserved', function (): void {
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);

    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '4.000000',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    // Ready creates the canonical reservation but does not physically ship it.
    app(InventoryOperationService::class)->markReady($delivery, $actor);

    $return = app(InventoryReturnService::class)->createSupplierReturn(
        $actor,
        $supplier,
        $warehouse,
    );

    expect(fn () => app(InventoryReturnService::class)->addSupplierLine(
        $return,
        $variant,
        (int) $variant->unit_id,
        '2.000000',
        StockCondition::Saleable,
        (int) $lot->getKey(),
    ))->toThrow(
        DomainException::class,
        'does not have enough eligible quantity',
    );
});

it('cancels a ready return with a reason without mutating frozen notes', function (): void {
    [$delivery, $deliveryLine, $warehouse, , $lot, $actor] = completedCustomerGrainDelivery();
    $service = app(InventoryReturnService::class);
    $return = $service->createCustomerReturn(
        $actor,
        $delivery,
        $warehouse,
        'Original return reason',
        'Original immutable notes',
    );
    $line = $service->addCustomerLine($return, $deliveryLine, '1.000000', (int) $lot->getKey());
    $service->inspectLine($line, InventoryReturnDisposition::Saleable, $actor);
    $ready = $service->markReady($return, $actor);

    $cancelled = $service->cancel($ready, $actor, 'Inspection process aborted');

    expect($cancelled->status)->toBe(InventoryReturnStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Inspection process aborted')
        ->and($cancelled->notes)->toBe('Original immutable notes')
        ->and(InventoryMovement::query()
            ->where('source_type', 'inventory_return')
            ->where('source_id', $return->getKey())
            ->count())->toBe(0);
});
