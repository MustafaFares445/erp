<?php

declare(strict_types=1);

use App\Enums\InventoryReturnDisposition;
use App\Enums\MovementType;
use App\Enums\StockCondition;
use App\Enums\WarrantyEntitlementState;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyEntitlement;
use App\Services\Inventory\InventoryReturnService;
use App\Services\Support\WarrantyActivationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function batch70ReturnInvoke(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(InventoryReturnService::class, $method)
        ->invoke(app(InventoryReturnService::class), ...$arguments);
}

it('covers warranty activation early-return guards', function (): void {
    $pending = Shipment::factory()->create();
    expect(app(WarrantyActivationService::class)->activateForShipment($pending))->toBe(0);

    $deliveryWithoutCustomer = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => null,
    ]);
    $arrivedWithoutCustomer = Shipment::factory()->arrived()->create([
        'inventory_operation_id' => $deliveryWithoutCustomer->getKey(),
        'confirmed_at' => now(),
    ]);
    expect(app(WarrantyActivationService::class)->activateForShipment($arrivedWithoutCustomer))->toBe(0);

    $customer = CustomerProfile::factory()->create();
    $emptyDelivery = InventoryOperation::factory()->delivery()->for($customer, 'customer')->done()->create();
    $arrivedWithoutSerialMovements = Shipment::factory()->forCustomer($customer)->arrived()->create([
        'inventory_operation_id' => $emptyDelivery->getKey(),
        'confirmed_at' => now(),
    ]);
    expect(app(WarrantyActivationService::class)->activateForShipment($arrivedWithoutSerialMovements))->toBe(0);
});

it('covers warranty activation when a delivered serial variant was soft deleted', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create();
    $delivery = InventoryOperation::factory()->delivery()->for($customer, 'customer')->done()->create();

    InventoryMovement::factory()->for($variant, 'productVariant')->create([
        'source_type' => 'inventory_operation',
        'source_id' => $delivery->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'movement_type' => MovementType::Sale,
        'quantity' => -1,
    ]);

    $shipment = Shipment::factory()->forCustomer($customer)->arrived()->create([
        'inventory_operation_id' => $delivery->getKey(),
        'confirmed_at' => now(),
    ]);

    $variant->delete();

    expect(app(WarrantyActivationService::class)->activateForShipment($shipment))->toBe(0);
});

it('transfers the remaining transferable warranty term to a new customer', function (): void {
    $oldCustomer = CustomerProfile::factory()->create();
    $newCustomer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'warranty_duration_value' => null,
        'warranty_duration_unit' => null,
    ]);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create();

    $old = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'customer_id' => $oldCustomer->getKey(),
        'state' => WarrantyEntitlementState::Active,
        'transferable' => true,
        'starts_on' => today()->subMonths(2),
        'expires_on' => today()->addMonths(10),
    ]);

    $delivery = InventoryOperation::factory()->delivery()->for($newCustomer, 'customer')->done()->create();
    InventoryMovement::factory()->for($variant, 'productVariant')->create([
        'source_type' => 'inventory_operation',
        'source_id' => $delivery->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'movement_type' => MovementType::Sale,
        'quantity' => -1,
    ]);
    $shipment = Shipment::factory()->forCustomer($newCustomer)->arrived()->create([
        'inventory_operation_id' => $delivery->getKey(),
        'confirmed_at' => now(),
    ]);

    expect(app(WarrantyActivationService::class)->activateForShipment($shipment))->toBe(1);

    $transferred = WarrantyEntitlement::query()
        ->where('serialized_inventory_unit_id', $unit->getKey())
        ->whereKeyNot($old->getKey())
        ->latest('id')
        ->firstOrFail();

    expect($transferred->state)->toBe(WarrantyEntitlementState::Active)
        ->and($transferred->starts_on?->toDateString())->toBe($old->starts_on?->toDateString())
        ->and($transferred->expires_on?->toDateString())->toBe($old->expires_on?->toDateString());
});

it('rejects a customer return line when its canonical delivery movement is missing', function (): void {
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->create();
    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $customer->getKey(),
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $line = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $delivery->getKey(),
        'product_variant_id' => $variant->getKey(),
        'inventory_lot_id' => $lot->getKey(),
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
    ]);
    $return = InventoryReturn::factory()->customer()->create([
        'customer_id' => $customer->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'original_inventory_operation_id' => $delivery->getKey(),
    ]);

    expect(fn () => app(InventoryReturnService::class)->addCustomerLine(
        $return,
        $line,
        '1.000000',
        (int) $lot->getKey(),
    ))->toThrow(DomainException::class, 'original delivery movement cannot be resolved');
});

it('covers supplier-return provenance lot and missing canonical receipt movement guards', function (): void {
    $supplier = Supplier::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $lotA = InventoryLot::factory()->for($variant, 'productVariant')->create();
    $lotB = InventoryLot::factory()->for($variant, 'productVariant')->create();

    $receiptA = InventoryOperation::factory()->receipt()->done()->create([
        'supplier_id' => $supplier->getKey(),
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);
    $receiptB = InventoryOperation::factory()->receipt()->done()->create([
        'supplier_id' => $supplier->getKey(),
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);
    $foreignLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $receiptB->getKey(),
        'product_variant_id' => $variant->getKey(),
        'inventory_lot_id' => $lotA->getKey(),
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
    ]);
    $return = InventoryReturn::factory()->supplier()->create([
        'supplier_id' => $supplier->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'original_inventory_operation_id' => $receiptA->getKey(),
    ]);

    expect(fn () => app(InventoryReturnService::class)->addSupplierLine(
        $return,
        $variant,
        (int) $variant->unit_id,
        '1.000000',
        StockCondition::Saleable,
        (int) $lotA->getKey(),
        null,
        $foreignLine,
    ))->toThrow(DomainException::class, 'not valid provenance');

    $receiptLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $receiptA->getKey(),
        'product_variant_id' => $variant->getKey(),
        'inventory_lot_id' => $lotA->getKey(),
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
    ]);

    expect(fn () => app(InventoryReturnService::class)->addSupplierLine(
        $return,
        $variant,
        (int) $variant->unit_id,
        '1.000000',
        StockCondition::Saleable,
        (int) $lotB->getKey(),
        null,
        $receiptLine,
    ))->toThrow(DomainException::class, 'lot does not match');

    expect(fn () => app(InventoryReturnService::class)->addSupplierLine(
        $return,
        $variant,
        (int) $variant->unit_id,
        '1.000000',
        StockCondition::Saleable,
        (int) $lotA->getKey(),
        null,
        $receiptLine,
    ))->toThrow(DomainException::class, 'canonical receipt movement cannot be resolved');
});

it('rejects customer posting when the original delivery variant was soft deleted', function (): void {
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->create();
    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $customer->getKey(),
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $deliveryLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $delivery->getKey(),
        'product_variant_id' => $variant->getKey(),
        'inventory_lot_id' => $lot->getKey(),
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
    ]);
    $return = InventoryReturn::factory()->customer()->create([
        'customer_id' => $customer->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'original_inventory_operation_id' => $delivery->getKey(),
    ]);
    $returnLine = InventoryReturnLine::factory()->create([
        'inventory_return_id' => $return->getKey(),
        'product_variant_id' => $variant->getKey(),
        'inventory_lot_id' => $lot->getKey(),
        'original_inventory_operation_line_id' => $deliveryLine->getKey(),
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
        'disposition' => InventoryReturnDisposition::Saleable,
        'inspected_at' => now(),
    ]);

    $variant->delete();

    expect(fn (): mixed => batch70ReturnInvoke(
        'customerPostingCommands',
        $return,
        new Collection([$returnLine]),
        $actor,
    ))->toThrow(DomainException::class, 'original delivery variant no longer exists');
});
