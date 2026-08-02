<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryOperations\Pages\EditInventoryOperation;
use App\Filament\Resources\InventoryOperations\Pages\ListInternalTransfers;
use App\Filament\Resources\InventoryOperations\Pages\ListReceipts;
use App\Filament\Resources\InventoryOperations\Pages\ViewInventoryOperation;
use App\Filament\Resources\InventoryOperations\Schemas\OperationLinesRepeater;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function inventoryOperationPreparer(): User
{
    $role = Role::firstOrCreate(['name' => 'inventory-operation-preparer', 'guard_name' => 'web']);
    $role->givePermissionTo([
        InventoryPermission::ReceiptView->value,
        InventoryPermission::ReceiptCreate->value,
        InventoryPermission::DeliveryView->value,
        InventoryPermission::DeliveryCreate->value,
        InventoryPermission::TransferView->value,
        InventoryPermission::TransferCreate->value,
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function inventoryOperationApprover(): User
{
    $role = Role::firstOrCreate(['name' => 'inventory-operation-approver', 'guard_name' => 'web']);
    $role->givePermissionTo([
        InventoryPermission::ReceiptView->value,
        InventoryPermission::ReceiptCreate->value,
        InventoryPermission::ReceiptConfirm->value,
        InventoryPermission::DeliveryView->value,
        InventoryPermission::DeliveryCreate->value,
        InventoryPermission::DeliveryConfirm->value,
        InventoryPermission::TransferView->value,
        InventoryPermission::TransferCreate->value,
        InventoryPermission::TransferConfirm->value,
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * @return array<string, mixed>
 */
function inventoryOperationLineAttributes(InventoryOperation $operation): array
{
    $variant = ProductVariant::factory()->create();
    $warehouseId = $operation->source_warehouse_id ?? $operation->destination_warehouse_id;

    if (is_int($warehouseId)) {
        InventoryStock::factory()->for($variant)->for(Warehouse::find($warehouseId))->create([
            'on_hand_quantity' => '10.000',
            'available_quantity' => '10.000',
        ]);
    }

    return [
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
    ];
}

it('renders the inventory operations index with an enum stage', function (): void {
    $role = Role::firstOrCreate(['name' => 'inventory-operation-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::ReceiptView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    $operation = InventoryOperation::factory()->receipt()->create();

    $this->actingAs($user)
        ->get(InventoryOperationResource::getUrl('index'))
        ->assertOk()
        ->assertSee($operation->stage->label());
});

it('renders an inventory operation view with an enum stage', function (): void {
    $role = Role::firstOrCreate(['name' => 'inventory-operation-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::ReceiptView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    $operation = InventoryOperation::factory()->receipt()->create();

    $this->actingAs($user)
        ->get(InventoryOperationResource::getUrl('view', ['record' => $operation]))
        ->assertOk()
        ->assertSee($operation->stage->label());
});

it('filters operation line variants by the selected active product', function (): void {
    $product = Product::factory()->create();
    $matchingVariant = ProductVariant::factory()->for($product)->create(['sku' => 'MATCHING-SKU']);
    ProductVariant::factory()->for($product)->create(['sku' => 'INACTIVE-SKU', 'is_active' => false]);
    $otherProductVariant = ProductVariant::factory()->create(['sku' => 'OTHER-SKU']);

    $variantOptions = new ReflectionMethod(
        OperationLinesRepeater::class,
        'variantOptions',
    );
    expect($variantOptions->invoke(null, $product->getKey()))
        ->toBe([$matchingVariant->getKey() => 'MATCHING-SKU'])
        ->not->toContain($otherProductVariant->getKey());
});

it('renders the source document type as a readable label on deliveries', function (): void {
    $role = Role::firstOrCreate(['name' => 'inventory-delivery-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::DeliveryView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    InventoryOperation::factory()->delivery()->create([
        'source_document_type' => 'sales_order',
    ]);

    $this->actingAs($user)
        ->get(InventoryOperationResource::getUrl('deliveries'))
        ->assertOk()
        ->assertSee('Sales Order')
        ->assertDontSee('sales_order');
});

it('renders the internal transfers list page', function (): void {
    $role = Role::firstOrCreate(['name' => 'inventory-transfer-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::TransferView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    $operation = InventoryOperation::factory()->internalTransfer()->create();

    $this->actingAs($user)
        ->get(InventoryOperationResource::getUrl('transfers'))
        ->assertOk()
        ->assertSee($operation->stage->label());
});

it('overrides the create action data with the current page operation type', function (): void {
    $actions = (new ListReceipts)->getHeaderActions();
    $createAction = $actions[0];

    expect($createAction)->toBeInstanceOf(CreateAction::class);

    $createAction->data(['operation_type' => 'delivery', 'notes' => 'hello']);

    expect($createAction->getData())->toBe([
        'operation_type' => 'receipt',
        'notes' => 'hello',
    ]);
});

it('overrides the create action data for internal transfers too', function (): void {
    $actions = (new ListInternalTransfers)->getHeaderActions();
    $createAction = $actions[0];

    $createAction->data(['operation_type' => 'receipt']);

    expect($createAction->getData())->toBe(['operation_type' => 'internal_transfer']);
});

it('exposes model relations used across the inventory operation views', function (): void {
    $creator = User::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->create(['created_by' => $creator->getKey()]);
    $variant = ProductVariant::factory()->create();
    $package = Package::factory()->create(['warehouse_id' => $operation->destination_warehouse_id]);
    $lot = InventoryLot::factory()->for($variant)->create(['warehouse_id' => $operation->destination_warehouse_id]);
    $line = $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'package_id' => $package->getKey(),
        'inventory_lot_id' => $lot->getKey(),
    ]);
    InventoryMovement::query()->forceCreate([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $operation->destination_warehouse_id,
        'movement_type' => 'receipt',
        'quantity' => '1.000',
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
        'status' => 'confirmed',
        'created_by' => $creator->getKey(),
    ]);

    expect($operation->createdBy()->first()->is($creator))->toBeTrue()
        ->and($operation->lines()->first()->is($line))->toBeTrue()
        ->and($operation->movements()->count())->toBe(1)
        ->and($line->operation()->first()->is($operation))->toBeTrue()
        ->and($line->productVariant()->first()->is($variant))->toBeTrue()
        ->and($line->package()->first()->is($package))->toBeTrue()
        ->and($line->lot()->first()->is($lot))->toBeTrue();
});

it('resolves the polymorphic source document and the Waiting stage predicate', function (): void {
    $warehouse = Warehouse::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->create([
        'source_document_type' => Warehouse::class,
        'source_document_id' => $warehouse->getKey(),
    ]);
    $waiting = InventoryOperation::factory()->receipt()->waiting()->create();

    expect($operation->sourceDocument()->first()->is($warehouse))->toBeTrue()
        ->and($operation->isWaiting())->toBeFalse()
        ->and($waiting->isWaiting())->toBeTrue();
});

it('resolves the serialized unit relation on an operation line', function (): void {
    $variant = ProductVariant::factory()->create(['track_serials' => true]);
    $serializedUnit = SerializedInventoryUnit::factory()->create(['product_variant_id' => $variant->getKey()]);
    $operation = InventoryOperation::factory()->receipt()->create();
    $line = $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $serializedUnit->getKey(),
    ]);

    expect($line->serializedUnit()->first()->is($serializedUnit))->toBeTrue();
});

it('formats the stage infolist entry for a scalar or missing state', function (): void {
    $role = Role::firstOrCreate(['name' => 'inventory-operation-infolist-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::ReceiptView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    $operation = InventoryOperation::factory()->receipt()->create();

    $instance = Livewire::actingAs($user)
        ->test(ViewInventoryOperation::class, ['record' => $operation->getKey()])
        ->instance();

    $stageEntry = $instance->getSchema('infolist')->getComponent('stage');

    expect($stageEntry->formatState('draft'))->toBe('draft')
        ->and($stageEntry->formatState(null))->toBe('');
});

it('resolves an integer from various scalar inputs on the operation lines repeater', function (): void {
    $toInteger = new ReflectionMethod(OperationLinesRepeater::class, 'toInteger');

    expect($toInteger->invoke(null, 5))->toBe(5)
        ->and($toInteger->invoke(null, 5.9))->toBe(5)
        ->and($toInteger->invoke(null, '7'))->toBe(7)
        ->and($toInteger->invoke(null, 'not-a-number'))->toBeNull()
        ->and($toInteger->invoke(null, null))->toBeNull();
});

it('renders the create page form', function (): void {
    $preparer = inventoryOperationPreparer();

    $this->actingAs($preparer)
        ->get(InventoryOperationResource::getUrl('create'))
        ->assertOk();
});

it('renders the edit page for a draft operation and shows the delete action', function (): void {
    $preparer = inventoryOperationPreparer();
    $operation = InventoryOperation::factory()->receipt()->draft()->create();
    $operation->lines()->create(inventoryOperationLineAttributes($operation));

    Livewire::actingAs($preparer)
        ->test(EditInventoryOperation::class, ['record' => $operation->getKey()])
        ->assertActionVisible(DeleteAction::class)
        ->assertFormFieldExists('lines');

    $this->actingAs($preparer)
        ->get(InventoryOperationResource::getUrl('edit', ['record' => $operation]))
        ->assertOk();
});

it('denies edit page access once an operation has left Draft', function (): void {
    $preparer = inventoryOperationPreparer();
    $operation = InventoryOperation::factory()->receipt()->waiting()->create();

    $this->actingAs($preparer)
        ->get(InventoryOperationResource::getUrl('edit', ['record' => $operation]))
        ->assertForbidden();

    expect($preparer->can('update', $operation))->toBeFalse();
});

it('infers the product from an existing line variant and resets the variant when the product changes', function (): void {
    $preparer = inventoryOperationPreparer();
    $operation = InventoryOperation::factory()->receipt()->draft()->create();
    $variant = ProductVariant::factory()->create();
    $line = $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
    ]);
    $otherProduct = Product::factory()->create();
    ProductVariant::factory()->for($otherProduct)->create();

    $itemKey = 'record-'.$line->getKey();

    $test = Livewire::actingAs($preparer)
        ->test(EditInventoryOperation::class, ['record' => $operation->getKey()]);

    expect((int) $test->get('data.lines.'.$itemKey.'.product_id'))->toBe($variant->product_id);

    $test->set('data.lines.'.$itemKey.'.product_id', $otherProduct->getKey());

    expect($test->get('data.lines.'.$itemKey.'.product_variant_id'))->toBeNull();
});

it('filters the package field by the receipt destination or the delivery source warehouse', function (): void {
    $preparer = inventoryOperationPreparer();

    $destination = Warehouse::factory()->create();
    $matchingPackage = Package::factory()->create(['warehouse_id' => $destination->getKey()]);
    Package::factory()->create();
    $receipt = InventoryOperation::factory()->receipt()->draft()->create(['destination_warehouse_id' => $destination->getKey()]);
    $receiptLine = $receipt->lines()->create(inventoryOperationLineAttributes($receipt));

    Livewire::actingAs($preparer)
        ->test(EditInventoryOperation::class, ['record' => $receipt->getKey()])
        ->assertFormFieldExists('lines');

    $source = Warehouse::factory()->create();
    $sourcePackage = Package::factory()->create(['warehouse_id' => $source->getKey()]);
    $delivery = InventoryOperation::factory()->delivery()->draft()->create(['source_warehouse_id' => $source->getKey()]);
    $deliveryLine = $delivery->lines()->create(inventoryOperationLineAttributes($delivery));

    Livewire::actingAs($preparer)
        ->test(EditInventoryOperation::class, ['record' => $delivery->getKey()])
        ->assertFormFieldExists('lines');

    expect($matchingPackage->warehouse_id)->toBe($destination->getKey())
        ->and($sourcePackage->warehouse_id)->toBe($source->getKey())
        ->and($receiptLine->exists)->toBeTrue()
        ->and($deliveryLine->exists)->toBeTrue();
});

it('marks a draft receipt ready and completes it through the view page actions', function (): void {
    $approver = inventoryOperationApprover();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->draft()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '2.000', 'unit_id' => $variant->unit_id]);

    Livewire::actingAs($approver)
        ->test(ViewInventoryOperation::class, ['record' => $operation->getKey()])
        ->callAction('markReady')
        ->assertNotified();

    expect($operation->refresh()->isReady())->toBeTrue();

    Livewire::actingAs($approver)
        ->test(ViewInventoryOperation::class, ['record' => $operation->getKey()])
        ->callAction('complete')
        ->assertNotified();

    expect($operation->refresh()->isDone())->toBeTrue()
        ->and((float) InventoryStock::query()->where('warehouse_id', $destination->getKey())->value('on_hand_quantity'))->toBe(2.0);
});

it('dispatches and completes an internal transfer through the view page actions', function (): void {
    $approver = inventoryOperationApprover();
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($source)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'available_quantity' => '10.000']);
    $operation = InventoryOperation::factory()->internalTransfer()->draft()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '4.000', 'unit_id' => $variant->unit_id]);

    Livewire::actingAs($approver)
        ->test(ViewInventoryOperation::class, ['record' => $operation->getKey()])
        ->callAction('markReady')
        ->assertNotified();

    expect($operation->refresh()->isReady())->toBeTrue();

    Livewire::actingAs($approver)
        ->test(ViewInventoryOperation::class, ['record' => $operation->getKey()])
        ->callAction('dispatch')
        ->assertNotified();

    expect($operation->refresh()->isInTransit())->toBeTrue();

    Livewire::actingAs($approver)
        ->test(ViewInventoryOperation::class, ['record' => $operation->getKey()])
        ->callAction('complete')
        ->assertNotified();

    expect($operation->refresh()->isDone())->toBeTrue()
        ->and((float) InventoryStock::query()->where('warehouse_id', $destination->getKey())->value('on_hand_quantity'))->toBe(4.0);
});

it('cancels a draft operation through the view page action', function (): void {
    $approver = inventoryOperationApprover();
    $operation = InventoryOperation::factory()->receipt()->draft()->create();
    $operation->lines()->create(inventoryOperationLineAttributes($operation));

    Livewire::actingAs($approver)
        ->test(ViewInventoryOperation::class, ['record' => $operation->getKey()])
        ->callAction('cancel')
        ->assertNotified();

    expect($operation->refresh()->isCanceled())->toBeTrue();
});

it('hides the dispatch action for a receipt at Ready, since dispatch only applies to internal transfers', function (): void {
    $approver = inventoryOperationApprover();
    $operation = InventoryOperation::factory()->receipt()->ready()->create();

    Livewire::actingAs($approver)
        ->test(ViewInventoryOperation::class, ['record' => $operation->getKey()])
        ->assertActionHidden('dispatch')
        ->assertActionVisible('complete');
});

it('renders the stage bar without In Transit for a receipt and with it for an internal transfer', function (): void {
    $preparer = inventoryOperationPreparer();

    $receipt = InventoryOperation::factory()->receipt()->draft()->create();
    $receipt->lines()->create(inventoryOperationLineAttributes($receipt));

    $this->actingAs($preparer)
        ->get(InventoryOperationResource::getUrl('edit', ['record' => $receipt]))
        ->assertOk()
        ->assertDontSee(__('admin.inventory.operation.stages.in_transit'));

    $transfer = InventoryOperation::factory()->internalTransfer()->draft()->create();
    $transfer->lines()->create(inventoryOperationLineAttributes($transfer));

    $this->actingAs($preparer)
        ->get(InventoryOperationResource::getUrl('edit', ['record' => $transfer]))
        ->assertOk()
        ->assertSee(__('admin.inventory.operation.stages.in_transit'));
});

it('renders an empty stage bar when creating a new operation', function (): void {
    $preparer = inventoryOperationPreparer();

    $this->actingAs($preparer)
        ->get(InventoryOperationResource::getUrl('create'))
        ->assertOk()
        ->assertDontSee(__('admin.inventory.operation.stages.draft'));
});
