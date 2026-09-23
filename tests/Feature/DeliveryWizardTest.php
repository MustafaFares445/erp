<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\OperationType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryOperations\Pages\CreateInventoryOperation;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

/**
 * Builds an actor allowed to create internal transfers — the only operation type
 * {@see CreateInventoryOperation::authorizeAccess()} still lets through this generic
 * wizard page (see App\Filament\Resources\InventoryOperations\Pages\CreateInventoryOperation).
 * Manual delivery creation was retired from this page; these tests mount the page with
 * `operation_type=internal_transfer` purely so `Livewire::test()->instance()` is non-null,
 * then reflect into private helper methods that never actually branch on operation type.
 */
function contextualTransferActor(): User
{
    $createPermission = Permission::findOrCreate(InventoryPermission::TransferCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::TransferView->value, 'web');
    $role = Role::findOrCreate('contextual-transfer-wizard-private-method-actor', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    return $actor;
}

it('returns a 404 when creating a delivery through the generic inventory-operation wizard', function (): void {
    // App\Filament\Resources\InventoryOperations\Pages\CreateInventoryOperation::authorizeAccess()
    // now throws for every operation type except OperationType::InternalTransfer. Even an actor
    // holding the delivery permissions below can no longer reach this route: deliveries are
    // created through the logistics outbound-fulfillment flow instead (see
    // App\Services\Logistics\OutboundFulfillmentService and App\Filament\Pages\LogisticsOutboundQueue).
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-wizard-denied-actor', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    $this->actingAs($actor)
        ->get(InventoryOperationResource::getUrl('create', ['operation_type' => OperationType::Delivery->value]))
        ->assertNotFound();
});

it('limits product and warehouse options to stock available in the selected warehouse and reports the available quantity', function (): void {
    $actor = contextualTransferActor();

    $warehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $availableVariant = ProductVariant::factory()->for(Product::factory()->create(['name' => 'Available Product']))->create();
    $otherWarehouseVariant = ProductVariant::factory()->for(Product::factory()->create(['name' => 'Remote Product']))->create();
    InventoryStock::factory()->for($availableVariant)->for($warehouse)->create(['available_quantity' => '12.500']);
    InventoryStock::factory()->for($otherWarehouseVariant)->for($otherWarehouse)->create(['available_quantity' => '8.000']);

    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $productOptionsMethod = new ReflectionMethod(CreateInventoryOperation::class, 'productOptions');
    $warehouseOptionsMethod = new ReflectionMethod(CreateInventoryOperation::class, 'warehouseOptions');
    $availableQuantityMethod = new ReflectionMethod(CreateInventoryOperation::class, 'availableQuantity');

    expect($productOptionsMethod->invoke($component->instance(), $warehouse->getKey()))
        ->toHaveKey($availableVariant->product_id)
        ->not->toHaveKey($otherWarehouseVariant->product_id)
        ->and($productOptionsMethod->invoke($component->instance(), null))
        ->toHaveKeys([$availableVariant->product_id, $otherWarehouseVariant->product_id])
        ->and($warehouseOptionsMethod->invoke($component->instance(), [[
            'product_id' => $availableVariant->product_id,
        ]]))
        ->toHaveKey($warehouse->getKey())
        ->not->toHaveKey($otherWarehouse->getKey())
        ->and($availableQuantityMethod->invoke($component->instance(), $availableVariant->getKey(), $warehouse->getKey()))
        ->toBe(12.5);
});

it('uses the forced operation type for a non-contextual create page', function (): void {
    $actor = contextualTransferActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $mutate = new ReflectionMethod(CreateInventoryOperation::class, 'mutateFormDataBeforeCreate');

    expect($component->instance()->hasFormWrapper())->toBeTrue()
        ->and($mutate->invoke($component->instance(), ['notes' => 'Transfer']))
        ->toMatchArray(['operation_type' => OperationType::InternalTransfer->value]);

    $modelKey = new ReflectionMethod(CreateInventoryOperation::class, 'modelKey');
    $model = new InventoryOperation;
    $model->setAttribute('id', '42');

    expect($modelKey->invoke(null, $model))->toBe(42);
});

it('resolves a model key from a non-incrementing string identifier', function (): void {
    $model = new class extends Model
    {
        public $incrementing = false;

        protected $keyType = 'string';
    };
    $model->setAttribute('id', '99');

    $modelKey = new ReflectionMethod(CreateInventoryOperation::class, 'modelKey');

    expect($modelKey->invoke(null, $model))->toBe(99);
});

it('persists an automatically generated tracking number for a shipment', function (): void {
    $shipment = Shipment::factory()->create(['tracking_number' => null]);

    expect($shipment->fresh()->tracking_number)
        ->toBe($shipment->tracking_number)
        ->toStartWith('TRK-');
});

it('limits serial number options to available devices in the selected warehouse and requires them for machine products', function (): void {
    $actor = contextualTransferActor();

    $warehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $regularVariant = ProductVariant::factory()->create();
    $device = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($warehouse)->create(['status' => SerializedInventoryUnitStatus::Available]);
    $pendingDevice = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($warehouse)->create(['status' => SerializedInventoryUnitStatus::Pending]);
    $otherWarehouseDevice = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($otherWarehouse)->create(['status' => SerializedInventoryUnitStatus::Available]);

    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $requiresSerialsMethod = new ReflectionMethod(CreateInventoryOperation::class, 'requiresSerials');
    $serializedUnitOptionsMethod = new ReflectionMethod(CreateInventoryOperation::class, 'serializedUnitOptions');

    expect($requiresSerialsMethod->invoke($component->instance(), $variant->getKey()))->toBeTrue()
        ->and($requiresSerialsMethod->invoke($component->instance(), $regularVariant->getKey()))->toBeFalse()
        ->and($serializedUnitOptionsMethod->invoke($component->instance(), $variant->getKey(), $warehouse->getKey()))
        ->toHaveKey($device->getKey())
        ->not->toHaveKey($pendingDevice->getKey())
        ->not->toHaveKey($otherWarehouseDevice->getKey());
});

it('refuses to build a delivery group without an authenticated actor', function (): void {
    $actor = contextualTransferActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    Auth::logout();

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'createDeliveryGroup');

    expect(fn (): InventoryOperation => $method->invoke($component->instance(), ['customer_id' => 1]))
        ->toThrow(NotFoundHttpException::class);
});

it('refuses to build a delivery group without a customer id', function (): void {
    $actor = contextualTransferActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'createDeliveryGroup');

    expect(fn (): InventoryOperation => $method->invoke($component->instance(), []))
        ->toThrow(ValidationException::class);
});

it('refuses to build a delivery group for a customer without delivery coordinates', function (): void {
    $actor = contextualTransferActor();
    $customer = CustomerProfile::factory()->create(['latitude' => null, 'longitude' => null]);
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'createDeliveryGroup');

    expect(fn (): InventoryOperation => $method->invoke($component->instance(), ['customer_id' => $customer->getKey()]))
        ->toThrow(ValidationException::class);
});

it('refuses to build a delivery group naming a nonexistent responsible user', function (): void {
    $actor = contextualTransferActor();
    $customer = CustomerProfile::factory()->create();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'createDeliveryGroup');

    expect(fn (): InventoryOperation => $method->invoke($component->instance(), [
        'customer_id' => $customer->getKey(),
        'responsible_id' => $customer->getKey() + 999_999,
    ]))->toThrow(ValidationException::class);
});

it('refuses to build a delivery group with a non-string scheduled_at value', function (): void {
    $actor = contextualTransferActor();
    $customer = CustomerProfile::factory()->create();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'createDeliveryGroup');

    expect(fn (): InventoryOperation => $method->invoke($component->instance(), [
        'customer_id' => $customer->getKey(),
        'scheduled_at' => ['not' => 'a string'],
    ]))->toThrow(ValidationException::class);
});

it('labels a delivery batch option with its expiry date when the lot carries one', function (): void {
    $actor = contextualTransferActor();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $expiry = today()->addMonths(3);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'expires_at' => $expiry,
    ]);

    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'lotOptions');
    $options = $method->invoke($component->instance(), $variant->getKey(), $warehouse->getKey());

    expect($options)->toHaveKey($lot->getKey())
        ->and($options[$lot->getKey()])->toContain($expiry->toDateString());
});

it('returns no serial number options when either the variant or the warehouse is missing', function (): void {
    $actor = contextualTransferActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'serializedUnitOptions');

    expect($method->invoke($component->instance(), null, 5))->toBe([])
        ->and($method->invoke($component->instance(), 5, null))->toBe([]);
});

it('refuses to render the delivery map without a configured routing service URL', function (): void {
    $actor = contextualTransferActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    config(['services.osrm.url' => null]);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'routingServiceUrl');

    expect(fn (): string => $method->invoke($component->instance()))->toThrow(LogicException::class);
});

it('skips malformed shipment and assignment entries while aggregating delivery products', function (): void {
    $actor = contextualTransferActor();
    $variant = ProductVariant::factory()->create();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'productsFromShipments');

    $result = $method->invoke($component->instance(), [
        'not-a-shipment-array',
        ['assignments' => 'not-an-array'],
        ['assignments' => [
            'not-an-assignment-array',
            ['product_variant_id' => null, 'product_id' => null, 'quantity' => 2],
            ['product_variant_id' => $variant->getKey(), 'quantity' => 'not-numeric'],
            ['product_variant_id' => $variant->getKey(), 'quantity' => 3],
        ]],
    ]);

    expect($result)->toBe([[
        'product_variant_id' => $variant->getKey(),
        'quantity' => 3.0,
    ]]);
});

it('leaves malformed shipment and assignment entries untouched while normalizing delivery type', function (): void {
    $actor = contextualTransferActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'normalizedShipments');

    $result = $method->invoke($component->instance(), [
        'not-a-shipment-array',
        [
            'delivery_type' => 'inner',
            'assignments' => [
                'not-an-assignment-array',
                ['product_variant_id' => 5],
            ],
        ],
    ]);

    expect($result[0])->toBe('not-a-shipment-array')
        ->and($result[1]['assignments'][0])->toBe('not-an-assignment-array');
});

it('skips blank and non-string parts while summarizing a customer delivery location', function (): void {
    $actor = contextualTransferActor();
    $customer = CustomerProfile::factory()->create([
        'address' => null,
        'city' => '',
        'country' => 'AE',
    ]);
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $summaryMethod = new ReflectionMethod(CreateInventoryOperation::class, 'customerLocationSummary');
    $countryNameMethod = new ReflectionMethod(CreateInventoryOperation::class, 'displayCountryName');

    expect($summaryMethod->invoke($component->instance(), $customer))
        ->toBe($countryNameMethod->invoke($component->instance(), 'AE'));
});

it('resolves a display name for a country code and falls back to the raw value otherwise', function (): void {
    $actor = contextualTransferActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'displayCountryName');

    expect($method->invoke($component->instance(), null))->toBeNull()
        ->and($method->invoke($component->instance(), ''))->toBeNull()
        ->and($method->invoke($component->instance(), 'United Arab Emirates'))->toBe('United Arab Emirates')
        ->and($method->invoke($component->instance(), 'XX'))->toBe('XX');
});

it('ignores a non-array shipment entry while collecting selected warehouse ids', function (): void {
    $actor = contextualTransferActor();
    $warehouse = Warehouse::factory()->create();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'selectedWarehouseIds');

    expect($method->invoke($component->instance(), ['not-a-shipment', ['warehouse_id' => $warehouse->getKey()]]))
        ->toBe([$warehouse->getKey()]);
});

it('excludes active warehouses missing either delivery coordinate from the map options', function (): void {
    $actor = contextualTransferActor();
    Warehouse::factory()->create(['is_active' => true, 'latitude' => null, 'longitude' => 55.27]);
    Warehouse::factory()->create(['is_active' => true, 'latitude' => 25.21, 'longitude' => null]);
    $complete = Warehouse::factory()->create(['is_active' => true, 'latitude' => 25.21, 'longitude' => 55.27]);
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'deliveryMapWarehouseOptions');

    $options = $method->invoke($component->instance());

    expect(collect($options)->pluck('id')->all())->toBe([$complete->getKey()]);
});

it('returns no address for a warehouse id that cannot be resolved', function (): void {
    $actor = contextualTransferActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'warehouseAddress');

    expect($method->invoke($component->instance(), null))->toBeNull()
        ->and($method->invoke($component->instance(), 'not-numeric'))->toBeNull();
});
it('covers delivery wizard reactive callbacks for customer quantity and serial state', function (): void {
    $actor = contextualTransferActor();
    $test = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);
    $page = $test->instance();
    $page->isContextualDelivery = true;
    $page->selectedOperationType = OperationType::Delivery;

    $getSteps = new ReflectionMethod(CreateInventoryOperation::class, 'getSteps');
    $steps = $getSteps->invoke($page);
    $schema = Schema::make($page)->components($steps);
    $components = collect($schema->getFlatComponents(withHidden: true));

    $customer = $components->first(
        static fn (mixed $component): bool => $component instanceof Select
            && $component->getName() === 'customer_id',
    );
    $shipments = $components->first(
        static fn (mixed $component): bool => $component instanceof Repeater
            && $component->getName() === 'shipments',
    );

    expect($customer)->toBeInstanceOf(Select::class)
        ->and($shipments)->toBeInstanceOf(Repeater::class);

    $afterStateUpdated = new ReflectionProperty($customer, 'afterStateUpdated');
    /** @var array<int, Closure> $customerCallbacks */
    $customerCallbacks = $afterStateUpdated->getValue($customer);
    $customerSet = Mockery::mock(Set::class);
    $customerSet->shouldReceive('__invoke')->once()->with('shipments', []);
    $customerCallbacks[0]($customerSet);
    $shipmentFields = collect($shipments->getChildSchema()?->getFlatComponents(withHidden: true) ?? []);
    $assignments = $shipmentFields->first(
        static fn (mixed $component): bool => $component instanceof Repeater
            && $component->getName() === 'assignments',
    );
    expect($assignments)->toBeInstanceOf(Repeater::class);

    $assignmentFields = collect($assignments->getChildSchema()?->getFlatComponents(withHidden: true) ?? []);
    $quantity = $assignmentFields->first(
        static fn (mixed $component): bool => $component instanceof TextInput
            && $component->getName() === 'quantity',
    );
    $serials = $assignmentFields->first(
        static fn (mixed $component): bool => $component instanceof Select
            && $component->getName() === 'serialized_inventory_unit_ids',
    );

    expect($quantity)->toBeInstanceOf(TextInput::class)
        ->and($serials)->toBeInstanceOf(Select::class);

    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '2.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '2.000000',
    ]);
    $quantityGet = Mockery::mock(Get::class);
    $quantityGet->shouldReceive('__invoke')->with('product_variant_id')->andReturn($variant->getKey());
    $quantityGet->shouldReceive('__invoke')->with('../../warehouse_id')->andReturn($warehouse->getKey());
    $quantitySet = Mockery::mock(Set::class);
    $quantitySet->shouldReceive('__invoke')->once()->with('quantity', 2.0);

    $quantityAfter = new ReflectionProperty($quantity, 'afterStateUpdated');
    /** @var array<int, Closure> $quantityCallbacks */
    $quantityCallbacks = $quantityAfter->getValue($quantity);
    $quantityCallbacks[0]($quantityGet, $quantitySet, 5);

    $placeholderProperty = new ReflectionProperty($serials, 'placeholder');
    /** @var Closure $placeholder */
    $placeholder = $placeholderProperty->getValue($serials);
    $serialGet = Mockery::mock(Get::class);
    $serialGet->shouldReceive('__invoke')->twice()->with('quantity')->andReturn(3);

    expect($placeholder($serialGet))->toBe('Select 3 serial number(s).');
});
it('routes contextual delivery record creation through the delivery group with a null schedule', function (): void {
    $actor = contextualTransferActor();
    $test = Livewire::withQueryParams(['operation_type' => OperationType::InternalTransfer->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);
    $page = $test->instance();
    $page->isContextualDelivery = true;
    $page->selectedOperationType = OperationType::Delivery;

    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);

    $create = new ReflectionMethod(CreateInventoryOperation::class, 'handleRecordCreation');
    $delivery = $create->invoke($page, [
        'customer_id' => $customer->getKey(),
        'scheduled_at' => null,
        'shipments' => [[
            'warehouse_id' => $warehouse->getKey(),
            'assignments' => [[
                'product_variant_id' => $variant->getKey(),
                'quantity' => 1,
                'inventory_lot_id' => $lot->getKey(),
            ]],
        ]],
    ]);

    expect($delivery)->toBeInstanceOf(InventoryOperation::class)
        ->and($delivery->customer_id)->toBe($customer->getKey());
});
