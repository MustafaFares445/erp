<?php

declare(strict_types=1);

use App\Enums\DeliveryDocument;
use App\Enums\InventoryPermission;
use App\Enums\OperationType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryOperations\Pages\CreateInventoryOperation;
use App\Filament\Resources\InventoryOperations\Pages\EditInventoryOperation;
use App\Filament\Resources\InventoryOperations\Pages\ListDeliveries;
use App\Filament\Resources\InventoryOperations\Pages\ListInternalTransfers;
use App\Filament\Resources\InventoryOperations\Pages\ListReceipts;
use App\Filament\Resources\InventoryOperations\Pages\ViewInventoryOperation;
use App\Filament\Resources\InventoryOperations\Schemas\OperationLinesRepeater;
use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    $lot = $warehouseId === $operation->source_warehouse_id
        ? InventoryLot::factory()->for($variant, 'productVariant')->for(Warehouse::find($warehouseId))->create([
            'on_hand_quantity' => '10.000',
            'reserved_quantity' => '0.000',
            'expires_at' => null,
        ])
        : null;

    return [
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot?->getKey(),
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

it('does not render the source document column on deliveries', function (): void {
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
        ->assertDontSee('Source Document')
        ->assertDontSee('Sales Order');
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

it('resolves the delivery address and shipment relations and the non-delivery stage label', function (): void {
    $deliveryAddress = CustomerDeliveryAddress::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->create([
        'customer_id' => $deliveryAddress->customer_profile_id,
        'customer_delivery_address_id' => $deliveryAddress->getKey(),
    ]);
    $shipment = Shipment::factory()->create(['inventory_operation_id' => $delivery->getKey()]);
    $receipt = InventoryOperation::factory()->receipt()->create();

    expect($delivery->deliveryAddress()->first()->is($deliveryAddress))->toBeTrue()
        ->and($delivery->shipment()->first()->is($shipment))->toBeTrue()
        ->and($receipt->stageLabel())->toBe($receipt->stage->label());
});

it('labels a completed delivery as delivered', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->done()->create();

    expect($delivery->stageLabel())->toBe(__('admin.inventory.operation.stages.delivered'));
});

it('resolves the serialized unit relation on an operation line', function (): void {
    $variant = ProductVariant::factory()->machine()->create();
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

    expect($stageEntry->formatState('draft'))->toBe('Draft')
        ->and($stageEntry->formatState(null))->toBe('Draft');
});

it('titles a record by its operation type and number instead of a generic label', function (): void {
    $role = Role::firstOrCreate(['name' => 'inventory-operation-title-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::ReceiptView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    $numbered = InventoryOperation::factory()->receipt()->create(['operation_number' => 'OP-000042']);
    $draft = InventoryOperation::factory()->receipt()->draft()->create(['operation_number' => null]);

    expect((string) InventoryOperationResource::getRecordTitle($numbered))->toBe('Receipt OP-000042')
        ->and((string) InventoryOperationResource::getRecordTitle($draft))->toBe('Receipt');

    $viewInstance = Livewire::actingAs($user)
        ->test(ViewInventoryOperation::class, ['record' => $numbered->getKey()])
        ->instance();

    expect((string) $viewInstance->getTitle())->toBe('View Receipt OP-000042');
});

it('resolves an integer from various scalar inputs on the operation lines repeater', function (): void {
    $toInteger = new ReflectionMethod(OperationLinesRepeater::class, 'toInteger');

    expect($toInteger->invoke(null, 5))->toBe(5)
        ->and($toInteger->invoke(null, 5.9))->toBe(5)
        ->and($toInteger->invoke(null, '7'))->toBe(7)
        ->and($toInteger->invoke(null, 'not-a-number'))->toBeNull()
        ->and($toInteger->invoke(null, null))->toBeNull();
});

it('resolves operation line type and batch options from fallback state', function (): void {
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $warehouse = Warehouse::factory()->create();
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'lot_number' => null,
        'expires_at' => null,
        'on_hand_quantity' => '5.000',
        'reserved_quantity' => '0.000',
    ]);
    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->andReturnUsing(static fn (string $path): mixed => match ($path) {
        'product_id' => null,
        'product_variant_id' => $variant->getKey(),
        '../../source_warehouse_id' => $warehouse->getKey(),
        '../../operation_type' => 'delivery',
        default => null,
    });

    $type = new ReflectionMethod(OperationLinesRepeater::class, 'typeOf');
    $lotOptions = new ReflectionMethod(OperationLinesRepeater::class, 'lotOptions');
    $serializedOptions = new ReflectionMethod(OperationLinesRepeater::class, 'serializedUnitOptions');

    expect($type->invoke(null, $get))->toBe($variant->product->product_type)
        ->and($lotOptions->invoke(null, $get))->toHaveKey($lot->getKey())
        ->and($serializedOptions->invoke(null, $get))->toBe([]);
});

it('labels an operation line batch option with its expiry date when the lot carries one', function (): void {
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $warehouse = Warehouse::factory()->create();
    $expiry = today()->addMonths(2);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '5.000',
        'reserved_quantity' => '0.000',
        'expires_at' => $expiry,
    ]);
    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->andReturnUsing(static fn (string $path): mixed => match ($path) {
        'product_variant_id' => $variant->getKey(),
        '../../source_warehouse_id' => $warehouse->getKey(),
        default => null,
    });

    $lotOptions = new ReflectionMethod(OperationLinesRepeater::class, 'lotOptions');
    $options = $lotOptions->invoke(null, $get);

    expect($options)->toHaveKey($lot->getKey())
        ->and($options[$lot->getKey()])->toContain($expiry->toDateString());
});

it('returns no operation line serial number options without a selected variant', function (): void {
    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->with('product_variant_id')->andReturnNull();

    $serializedOptions = new ReflectionMethod(OperationLinesRepeater::class, 'serializedUnitOptions');

    expect($serializedOptions->invoke(null, $get))->toBe([]);
});

it('renders the create page form', function (): void {
    $preparer = inventoryOperationPreparer();

    $this->actingAs($preparer)
        ->get(InventoryOperationResource::getUrl('create'))
        ->assertOk();
});

it('renders the delivery wizard on the contextual delivery create page', function (): void {
    $preparer = inventoryOperationPreparer();

    $this->actingAs($preparer)
        ->get(InventoryOperationResource::getUrl('create', ['operation_type' => 'delivery']))
        ->assertOk()
        ->assertSee('Delivery Information')
        ->assertSee('Warehouse Allocation')
        ->assertSee('Tracking number')
        ->assertSee('Shipment attachments');
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

it('delegates non-inventory records to the parent edit handler', function (): void {
    $page = new EditInventoryOperation;
    $method = new ReflectionMethod(EditInventoryOperation::class, 'handleRecordUpdate');
    $user = User::factory()->create(['name' => 'Updated user']);

    expect($method->invoke($page, $user, ['name' => 'Edited user']))->toBeInstanceOf(User::class)
        ->and($user->fresh()->name)->toBe('Edited user');
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
    // The factory's default variant is a Grain, which is batch-tracked, so the line below has
    // to name the batch it draws from.
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'expires_at' => null]);
    $operation = InventoryOperation::factory()->internalTransfer()->draft()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '4.000', 'unit_id' => $variant->unit_id, 'inventory_lot_id' => $lot->getKey()]);

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

it('flags missing delivery documents in the delivery list and show page', function (): void {
    $role = Role::firstOrCreate(['name' => 'inventory-delivery-document-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::DeliveryView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    $delivery = InventoryOperation::factory()->delivery()->create();

    $this->actingAs($user)
        ->get(InventoryOperationResource::getUrl('deliveries'))
        ->assertOk()
        ->assertSee(__('admin.inventory.operation.documents_missing_count', ['count' => 7]));

    $this->actingAs($user)
        ->get(InventoryOperationResource::getUrl('view', ['record' => $delivery]))
        ->assertOk()
        ->assertSee('Missing: Payment Receipt');
});

it('filters the delivery list to records with missing documents', function (): void {
    $preparer = inventoryOperationPreparer();
    $missing = InventoryOperation::factory()->delivery()->create();
    $complete = InventoryOperation::factory()->delivery()->create();

    foreach (DeliveryDocument::cases() as $document) {
        $complete
            ->addMediaFromString('%PDF-1.4')
            ->usingFileName($document->value.'.pdf')
            ->toMediaCollection($document->value, 'local');
    }

    Livewire::actingAs($preparer)
        ->test(ListDeliveries::class)
        ->filterTable('missing_delivery_documents')
        ->assertCanSeeTableRecords([$missing])
        ->assertCanNotSeeTableRecords([$complete]);
});

it('does not add shipment tracking data to a delivery operation', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();

    expect($delivery->delivery_type->value)->toBe('inner')
        ->and(array_key_exists('tracking_number', $delivery->getAttributes()))->toBeFalse();
});

it('requires a customer when creating a delivery', function (): void {
    $user = inventoryOperationPreparer();
    $warehouse = Warehouse::factory()->create();

    Livewire::actingAs($user)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'operation_type' => 'delivery',
            'source_warehouse_id' => $warehouse->getKey(),
        ])
        ->call('create')
        ->assertHasFormErrors(['customer_id' => 'required']);
});

it('returns a 404 when the create page is visited with an unrecognized operation type', function (): void {
    $preparer = inventoryOperationPreparer();

    $this->actingAs($preparer)
        ->get(InventoryOperationResource::getUrl('create', ['operation_type' => 'bogus-type']))
        ->assertNotFound();
});

it('renders the create page for a forced receipt operation type with its type-specific title', function (): void {
    $preparer = inventoryOperationPreparer();

    $this->actingAs($preparer)
        ->get(InventoryOperationResource::getUrl('create', ['operation_type' => 'receipt']))
        ->assertOk()
        ->assertSee('Create '.OperationType::Receipt->label());
});

it('creates a receipt operation via the standard form with a forced operation type from the query string', function (): void {
    $preparer = inventoryOperationPreparer();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    Livewire::withQueryParams(['operation_type' => 'receipt'])
        ->actingAs($preparer)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'destination_warehouse_id' => $destination->getKey(),
            'lines' => [[
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
                'quantity' => 2,
                'unit_id' => $variant->unit_id,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operation = InventoryOperation::query()->where('operation_type', 'receipt')->sole();

    expect($operation->destination_warehouse_id)->toBe($destination->getKey())
        ->and($operation->lines()->sole()->product_variant_id)->toBe($variant->getKey());
});

it('creates a delivery operation via the standard form and stores its uploaded delivery documents', function (): void {
    Storage::fake('local');

    $preparer = inventoryOperationPreparer();
    $customer = CustomerProfile::factory()->create();
    $source = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($source)->create(['available_quantity' => '10.000']);
    // The factory's default variant is a Grain, which is batch-tracked, so the line below has
    // to name the batch it draws from.
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'expires_at' => null]);

    Livewire::actingAs($preparer)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'operation_type' => 'delivery',
            'customer_id' => $customer->getKey(),
            'source_warehouse_id' => $source->getKey(),
            'lines' => [[
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
                'quantity' => 2,
                'unit_id' => $variant->unit_id,
                'inventory_lot_id' => $lot->getKey(),
            ]],
            'payment_receipt' => UploadedFile::fake()->create('payment_receipt.pdf', 100, 'application/pdf'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operation = InventoryOperation::query()->where('operation_type', 'delivery')->sole();

    expect($operation->getFirstMedia(DeliveryDocument::PaymentReceipt->value))->not->toBeNull();
});

it('only shows the delivery documents fields for delivery operations', function (): void {
    $preparer = inventoryOperationPreparer();

    Livewire::actingAs($preparer)
        ->test(CreateInventoryOperation::class)
        ->fillForm(['operation_type' => 'delivery'])
        ->assertFormFieldIsVisible(DeliveryDocument::PaymentReceipt->value);

    Livewire::actingAs($preparer)
        ->test(CreateInventoryOperation::class)
        ->fillForm(['operation_type' => 'internal_transfer'])
        ->assertFormFieldIsHidden(DeliveryDocument::PaymentReceipt->value);
});

it('refuses a delivery document path that was not legitimately uploaded through the form', function (): void {
    $preparer = inventoryOperationPreparer();
    $customer = CustomerProfile::factory()->create();
    $source = Warehouse::factory()->create();

    Livewire::actingAs($preparer)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'operation_type' => 'delivery',
            'customer_id' => $customer->getKey(),
            'source_warehouse_id' => $source->getKey(),
            'payment_receipt' => 'delivery-documents/payment_receipt/tampered.pdf',
        ])
        ->call('create')
        ->assertHasFormErrors(['payment_receipt']);
});

it('updates a draft delivery, keeping an untouched document and storing a newly uploaded one', function (): void {
    Storage::fake('local');

    $preparer = inventoryOperationPreparer();
    $delivery = InventoryOperation::factory()->delivery()->draft()->create();
    $delivery->lines()->create(inventoryOperationLineAttributes($delivery));
    $delivery->addMediaFromString('%PDF-1.4')
        ->usingFileName('payment_receipt.pdf')
        ->toMediaCollection(DeliveryDocument::PaymentReceipt->value, 'local');

    Livewire::actingAs($preparer)
        ->test(EditInventoryOperation::class, ['record' => $delivery->getKey()])
        ->fillForm([
            'packing_list' => UploadedFile::fake()->create('packing_list.pdf', 100, 'application/pdf'),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $delivery->refresh();

    expect($delivery->getFirstMedia(DeliveryDocument::PaymentReceipt->value)?->file_name)->toBe('payment_receipt.pdf')
        ->and($delivery->getFirstMedia(DeliveryDocument::PackingList->value))->not->toBeNull();
});

it('shows the documents-complete state and a download link when every delivery document is attached', function (): void {
    $role = Role::firstOrCreate(['name' => 'inventory-delivery-document-complete-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::DeliveryView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    $delivery = InventoryOperation::factory()->delivery()->create();

    foreach (DeliveryDocument::cases() as $document) {
        $delivery->addMediaFromString('%PDF-1.4')
            ->usingFileName($document->value.'.pdf')
            ->toMediaCollection($document->value, 'local');
    }

    $this->actingAs($user)
        ->get(InventoryOperationResource::getUrl('view', ['record' => $delivery]))
        ->assertOk()
        ->assertSee(__('admin.inventory.operation.documents_complete'))
        ->assertSee(__('admin.inventory.operation.download'));
});

it('infers a fresh repeater line product type from its variant and offers matching batches and serials', function (): void {
    $preparer = inventoryOperationPreparer();
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $grainVariant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($grainVariant)->for($source)->create(['available_quantity' => '10.000']);
    $lot = InventoryLot::factory()->for($grainVariant, 'productVariant')->for($source)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'expires_at' => null,
    ]);
    $machineVariant = ProductVariant::factory()->machine()->create();
    InventoryStock::factory()->for($machineVariant)->for($source)->create(['available_quantity' => '1.000']);
    $device = SerializedInventoryUnit::factory()->for($machineVariant, 'productVariant')->for($source)->create(['status' => SerializedInventoryUnitStatus::Available]);

    Livewire::actingAs($preparer)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'operation_type' => 'internal_transfer',
            'source_warehouse_id' => $source->getKey(),
            'destination_warehouse_id' => $destination->getKey(),
            'lines' => [
                [
                    'product_id' => $grainVariant->product_id,
                    'product_variant_id' => $grainVariant->getKey(),
                    'quantity' => 2,
                    'unit_id' => $grainVariant->unit_id,
                    'inventory_lot_id' => $lot->getKey(),
                ],
                [
                    'product_id' => $machineVariant->product_id,
                    'product_variant_id' => $machineVariant->getKey(),
                    'quantity' => 1,
                    'unit_id' => $machineVariant->unit_id,
                    'serialized_inventory_unit_id' => $device->getKey(),
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operation = InventoryOperation::query()->where('operation_type', 'internal_transfer')->sole();

    expect($operation->lines()->count())->toBe(2);
});

it('returns no serial number options for a machine line before the source warehouse is chosen', function (): void {
    $preparer = inventoryOperationPreparer();
    $machineVariant = ProductVariant::factory()->machine()->create();

    Livewire::actingAs($preparer)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'operation_type' => 'internal_transfer',
            'lines' => [[
                'product_id' => $machineVariant->product_id,
                'product_variant_id' => $machineVariant->getKey(),
            ]],
        ])
        ->assertSee(__('admin.inventory.operation.fields.serialized_unit'));
});

it('offers pending serialized units for a machine line on a receipt', function (): void {
    $preparer = inventoryOperationPreparer();
    $destination = Warehouse::factory()->create();
    $machineVariant = ProductVariant::factory()->machine()->create();
    $pendingDevice = SerializedInventoryUnit::factory()->for($machineVariant, 'productVariant')->create(['status' => SerializedInventoryUnitStatus::Pending, 'warehouse_id' => null]);

    Livewire::withQueryParams(['operation_type' => 'receipt'])
        ->actingAs($preparer)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'destination_warehouse_id' => $destination->getKey(),
            'lines' => [[
                'product_id' => $machineVariant->product_id,
                'product_variant_id' => $machineVariant->getKey(),
                'quantity' => 1,
                'unit_id' => $machineVariant->unit_id,
                'serialized_inventory_unit_id' => $pendingDevice->getKey(),
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $operation = InventoryOperation::query()->where('operation_type', 'receipt')->sole();

    expect($operation->lines()->sole()->serialized_inventory_unit_id)->toBe($pendingDevice->getKey());
});

it('lets a receipt line register a brand-new serial number inline', function (): void {
    $preparer = inventoryOperationPreparer();
    $destination = Warehouse::factory()->create();
    $machineVariant = ProductVariant::factory()->machine()->create();

    Livewire::withQueryParams(['operation_type' => 'receipt'])
        ->actingAs($preparer)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'destination_warehouse_id' => $destination->getKey(),
            'lines' => [[
                'product_id' => $machineVariant->product_id,
                'product_variant_id' => $machineVariant->getKey(),
                'quantity' => 1,
                'unit_id' => $machineVariant->unit_id,
            ]],
        ])
        ->callAction(
            TestAction::make('createOption')->schemaComponent('lines.0.serialized_inventory_unit_id'),
            data: ['serial_number' => 'SN-NEW-001'],
        )
        ->assertHasNoActionErrors();

    $unit = SerializedInventoryUnit::query()->where('serial_number', 'SN-NEW-001')->sole();

    expect($unit->product_variant_id)->toBe($machineVariant->getKey())
        ->and($unit->status)->toBe(SerializedInventoryUnitStatus::Pending)
        ->and($unit->warehouse_id)->toBeNull();
});

it('hides the inline serial creation button for outbound machine lines', function (): void {
    $preparer = inventoryOperationPreparer();
    $source = Warehouse::factory()->create();
    $machineVariant = ProductVariant::factory()->machine()->create();
    $device = SerializedInventoryUnit::factory()->for($machineVariant, 'productVariant')->create([
        'status' => SerializedInventoryUnitStatus::Available,
        'warehouse_id' => $source->getKey(),
    ]);

    Livewire::withQueryParams(['operation_type' => 'internal_transfer'])
        ->actingAs($preparer)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'source_warehouse_id' => $source->getKey(),
            'lines' => [[
                'product_id' => $machineVariant->product_id,
                'product_variant_id' => $machineVariant->getKey(),
                'quantity' => 1,
                'unit_id' => $machineVariant->unit_id,
                'serialized_inventory_unit_id' => $device->getKey(),
            ]],
        ])
        ->assertActionHidden(TestAction::make('createOption')->schemaComponent('lines.0.serialized_inventory_unit_id'));
});
