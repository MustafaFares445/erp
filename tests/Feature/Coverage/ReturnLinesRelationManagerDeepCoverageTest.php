<?php

declare(strict_types=1);

use App\Enums\InventoryReturnDisposition;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Filament\Resources\Returns\RelationManagers\ReturnLinesRelationManager;
use App\Filament\Resources\Returns\ReturnResource;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function returnLinesManager(InventoryReturn $return): ReturnLinesRelationManager
{
    $manager = app(ReturnLinesRelationManager::class);
    $manager->ownerRecord = $return;
    $manager->pageClass = ReturnResource::class;

    return $manager;
}

function returnLinesCall(ReturnLinesRelationManager $manager, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod(ReturnLinesRelationManager::class, $method);

    return $reflection->invokeArgs($manager, $arguments);
}

function returnLinesGet(array $values): Get
{
    return new class($values) extends Get
    {
        public function __construct(private array $values) {}

        public function __invoke(string|Component $path = '', bool $isAbsolute = false): mixed
        {
            return $this->values[(string) $path] ?? null;
        }
    };
}

it('covers customer return action validation options and service adapter paths', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $delivery = InventoryOperation::factory()->delivery()->done()->create();
    $variant = ProductVariant::factory()->create();
    $line = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $delivery->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '2.000000',
    ]);
    $return = InventoryReturn::factory()->customer()->create([
        'warehouse_id' => $delivery->source_warehouse_id,
        'customer_id' => $delivery->customer_id,
        'original_inventory_operation_id' => $delivery->getKey(),
    ]);
    $manager = returnLinesManager($return);
    $action = returnLinesCall($manager, 'addCustomerLineAction');
    expect($action)->toBeInstanceOf(Action::class)
        ->and(returnLinesCall($manager, 'customerLineOptions'))->toHaveKey($line->getKey());

    $fn = $action->getActionFunction();
    expect(fn () => $fn([]))->toThrow(LogicException::class);
    $fn(['original_inventory_operation_line_id' => $line->getKey(), 'transaction_quantity' => 1.0]);
});

it('covers supplier return actions receipt options and lot serial selectors', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $supplier = Supplier::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $receipt = InventoryOperation::factory()->receipt()->done()->create([
        'supplier_id' => $supplier->getKey(),
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $lot = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create(['lot_number' => 'RET-COV']);
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '5.000000',
        'reserved_base_quantity' => '0.000000',
    ]);
    $receiptLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $receipt->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '5.000000',
        'inventory_lot_id' => $lot->getKey(),
    ]);
    $return = InventoryReturn::factory()->supplier()->create([
        'supplier_id' => $supplier->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'original_inventory_operation_id' => $receipt->getKey(),
    ]);
    $manager = returnLinesManager($return);
    expect(returnLinesCall($manager, 'supplierReceiptLineOptions'))->toHaveKey($receiptLine->getKey());
    $get = returnLinesGet(['product_variant_id' => $variant->getKey(), 'source_condition' => StockCondition::Saleable->value]);
    expect(returnLinesCall($manager, 'supplierLotOptions', $get))->toHaveKey($lot->getKey());

    $machine = ProductVariant::factory()->machine()->create();
    $serial = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $machine->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Saleable,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    $serialGet = returnLinesGet(['product_variant_id' => $machine->getKey(), 'source_condition' => StockCondition::Saleable->value]);
    expect(returnLinesCall($manager, 'supplierSerialOptions', $serialGet))->toHaveKey($serial->getKey());

    $action = returnLinesCall($manager, 'addSupplierLineAction');
    $fn = $action->getActionFunction();
    expect(fn () => $fn([]))->toThrow(LogicException::class);
    $fn([
        'product_variant_id' => $variant->getKey(),
        'source_condition' => StockCondition::Saleable->value,
        'transaction_quantity' => 1.0,
        'inventory_lot_id' => $lot->getKey(),
        'serialized_inventory_unit_id' => null,
        'original_inventory_operation_line_id' => $receiptLine->getKey(),
    ]);
});

it('covers inspect remove and helper guards', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $return = InventoryReturn::factory()->customer()->create();
    $line = InventoryReturnLine::factory()->create(['inventory_return_id' => $return->getKey()]);
    $manager = returnLinesManager($return);

    $inspect = returnLinesCall($manager, 'inspectAction');
    $inspectFn = $inspect->getActionFunction();
    expect(fn () => $inspectFn($line, []))->toThrow(LogicException::class);
    $inspectFn($line, [
        'disposition' => InventoryReturnDisposition::Saleable->value,
        'inspection_notes' => '  checked  ',
    ]);
    expect($line->fresh()?->disposition)->toBe(InventoryReturnDisposition::Saleable)
        ->and($line->fresh()?->inspection_notes)->toBe('checked');

    $remove = returnLinesCall($manager, 'removeAction');
    $removeFn = $remove->getActionFunction();
    $removeFn($line->fresh());
    expect(InventoryReturnLine::query()->find($line->getKey()))->toBeNull();

    expect(returnLinesCall($manager, 'actor'))->toBe($actor)
        ->and(returnLinesCall($manager, 'nullableInteger', 7))->toBe(7)
        ->and(returnLinesCall($manager, 'nullableInteger', '8'))->toBe(8)
        ->and(returnLinesCall($manager, 'nullableInteger', 'bad'))->toBeNull();

    auth()->logout();
    expect(fn (): mixed => returnLinesCall($manager, 'actor'))->toThrow(LogicException::class);

    $badManager = returnLinesManager($return);
    $badManager->ownerRecord = Warehouse::factory()->create();

    expect(fn (): mixed => returnLinesCall($badManager, 'returnRecord'))->toThrow(LogicException::class);

    $unsaved = new class extends Model {};
    expect(fn (): mixed => returnLinesCall($manager, 'integerKey', $unsaved))->toThrow(LogicException::class);
});

it('covers remaining return-line adapter branches', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $return = InventoryReturn::factory()->supplier()->create([
        'supplier_id' => $supplier->getKey(),
        'warehouse_id' => $warehouse->getKey(),
    ]);
    $manager = returnLinesManager($return);
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $lot = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create();
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Quarantine,
        'on_hand_base_quantity' => '2.000000',
        'reserved_base_quantity' => '0.000000',
    ]);
    expect(returnLinesCall($manager, 'supplierLotOptions', returnLinesGet([
        'product_variant_id' => $variant->getKey(),
        'source_condition' => StockCondition::Disposed->value,
    ])))->toBe([]);
    expect(returnLinesCall($manager, 'supplierLotOptions', returnLinesGet([
        'product_variant_id' => $variant->getKey(),
        'source_condition' => StockCondition::Quarantine->value,
    ])))->toHaveKey($lot->getKey());
    $machine = ProductVariant::factory()->machine()->create();
    $damagedSerial = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $machine->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Damaged,
        'status' => SerializedInventoryUnitStatus::Damaged,
    ]);
    expect(returnLinesCall($manager, 'supplierSerialOptions', returnLinesGet([
        'product_variant_id' => $machine->getKey(),
        'source_condition' => StockCondition::Disposed->value,
    ])))->toBe([]);
    expect(returnLinesCall($manager, 'supplierSerialOptions', returnLinesGet([
        'product_variant_id' => $machine->getKey(),
        'source_condition' => StockCondition::Damaged->value,
    ])))->toHaveKey($damagedSerial->getKey());

    $supplierAction = returnLinesCall($manager, 'addSupplierLineAction');
    $supplierFn = $supplierAction->getActionFunction();
    $supplierFn([
        'product_variant_id' => $variant->getKey(),
        'source_condition' => StockCondition::Quarantine->value,
        'transaction_quantity' => '1',
        'serialized_inventory_unit_id' => $damagedSerial->getKey(),
    ]);
});

it('covers customer string quantity and null inspection notes branches', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $delivery = InventoryOperation::factory()->delivery()->done()->create();
    $variant = ProductVariant::factory()->create();
    $deliveryLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $delivery->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '2.000000',
    ]);
    $return = InventoryReturn::factory()->customer()->create([
        'warehouse_id' => $delivery->source_warehouse_id,
        'customer_id' => $delivery->customer_id,
        'original_inventory_operation_id' => $delivery->getKey(),
    ]);
    $manager = returnLinesManager($return);
    $customerFn = returnLinesCall($manager, 'addCustomerLineAction')->getActionFunction();
    $customerFn([
        'original_inventory_operation_line_id' => $deliveryLine->getKey(),
        'transaction_quantity' => '1',
    ]);

    $line = InventoryReturnLine::factory()->create(['inventory_return_id' => $return->getKey()]);
    $inspectFn = returnLinesCall($manager, 'inspectAction')->getActionFunction();
    $inspectFn($line, [
        'disposition' => InventoryReturnDisposition::Quarantine->value,
        'inspection_notes' => 123,
    ]);
    expect($line->fresh()?->inspection_notes)->toBeNull();
});
