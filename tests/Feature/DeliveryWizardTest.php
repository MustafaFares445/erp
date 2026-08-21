<?php

declare(strict_types=1);

use App\Enums\DeliveryDocument;
use App\Enums\DeliveryType;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryOperations\Pages\CreateInventoryOperation;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function fakeStraightLineRouting(): void
{
    Http::fake(fn (): Response => Http::response(['code' => 'NoRoute', 'routes' => []]));
}

function contextualDeliveryActor(): User
{
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-wizard-private-method-actor', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    return $actor;
}

it('denies contextual delivery creation without the delivery permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(InventoryOperationResource::getUrl('create', ['operation_type' => 'delivery']))
        ->assertForbidden();
});

it('combines products and warehouse allocation in the second delivery wizard step', function (): void {
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-wizard-viewer', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    $this->actingAs($actor)
        ->get(InventoryOperationResource::getUrl('create', ['operation_type' => OperationType::Delivery->value]))
        ->assertSuccessful()
        ->assertSee('Warehouse Allocation')
        ->assertDontSee('Products and Quantities')
        ->assertDontSee('Warehouse id');
});

it('provides selected customer and warehouse locations to the delivery route map', function (): void {
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-map-viewer', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    $customer = CustomerProfile::factory()->create([
        'company_name' => 'Bright Orthodontics',
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ]);
    $warehouse = Warehouse::factory()->create([
        'name' => 'Cold Chain Storage',
        'latitude' => 33.5215,
        'longitude' => 36.2944,
    ]);
    fakeStraightLineRouting();

    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'shipments' => [[
                'warehouse_id' => $warehouse->getKey(),
            ]],
        ])
        ->assertSee('Customer delivery location')
        ->assertSee('Bright Orthodontics')
        ->assertSee('Cold Chain Storage')
        ->assertSee('customer-delivery-map-panel')
        ->assertSee('class="customer-delivery-map"', false);
});

it('limits delivery products to stock available in the selected warehouse and shows its quantity', function (): void {
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-stock-options-viewer', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $otherWarehouse = Warehouse::factory()->create();
    $availableVariant = ProductVariant::factory()->for(Product::factory()->create(['name' => 'Available Product']))->create();
    $otherWarehouseVariant = ProductVariant::factory()->for(Product::factory()->create(['name' => 'Remote Product']))->create();
    InventoryStock::factory()->for($availableVariant)->for($warehouse)->create(['available_quantity' => '12.500']);
    InventoryStock::factory()->for($otherWarehouseVariant)->for($otherWarehouse)->create(['available_quantity' => '8.000']);
    fakeStraightLineRouting();

    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'shipments' => [[
                'warehouse_id' => $warehouse->getKey(),
            ]],
        ]);

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

    $component
        ->fillForm([
            'shipments' => [[
                'warehouse_id' => $warehouse->getKey(),
                'assignments' => [[
                    'product_id' => $availableVariant->product_id,
                    'product_variant_id' => $availableVariant->getKey(),
                    'quantity' => 13,
                ]],
            ]],
        ])
        ->assertFormSet(['shipments.0.assignments.0.quantity' => 12.5]);

    // Stock dropping after the quantity was entered still gets caught at submission time.
    InventoryStock::query()
        ->where('product_variant_id', $availableVariant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->first()
        ->forceFill(['available_quantity' => '5.000'])
        ->save();

    $component
        ->call('create')
        ->assertHasFormErrors(['shipments.0.assignments.0.quantity']);
});

it('clamps the assignment quantity down to the available stock as soon as it is entered', function (): void {
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-quantity-clamp-viewer', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->for(Product::factory()->create())->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '1.000']);
    fakeStraightLineRouting();

    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'shipments' => [[
                'warehouse_id' => $warehouse->getKey(),
                'assignments' => [[
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => 3,
                ]],
            ]],
        ])
        ->assertFormSet(['shipments.0.assignments.0.quantity' => 1.0]);
});

it('shows a stock warning banner naming the variant when the requested quantity exceeds available stock', function (): void {
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-stock-warning-viewer', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->create(['name' => 'Scarce Widget Variant']);
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '8.000']);
    fakeStraightLineRouting();

    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'shipments' => [[
                'warehouse_id' => $warehouse->getKey(),
                'assignments' => [[
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => 8,
                ]],
            ]],
        ]);

    // Stock dropping after the quantity was entered still surfaces the warning banner.
    $stock->forceFill(['available_quantity' => '5.000'])->save();

    $component
        ->fillForm(['shipments.0.tracking_number' => 'TRACK-1'])
        ->assertSee('Not enough stock')
        ->assertSee('Scarce Widget Variant');
});

it('loads the published delivery map assets before the dynamic warehouse allocation step', function (): void {
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-map-assets-viewer', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    $this->actingAs($actor)
        ->get(InventoryOperationResource::getUrl('create', ['operation_type' => OperationType::Delivery->value]))
        ->assertSuccessful()
        ->assertSee('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', escape: false)
        ->assertSee('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', escape: false);

    $this->assertFileExists(public_path('js/app/components/customer-delivery-map.js'));
    $this->assertFileExists(public_path('css/app/customer-delivery-map.css'));
    expect(file_get_contents(resource_path('css/filament/customer-delivery-map.css')))
        ->toContain('.customer-delivery-map-panel__icon')
        ->toContain('isolation: isolate');

    expect(file_get_contents(resource_path('js/filament/customer-delivery-map.js')))
        ->toContain('permanent: true')
        ->toContain('customer-delivery-map__label')
        ->toContain('customer-delivery-map__label--muted');
});

it('creates ready warehouse deliveries from the contextual wizard', function (): void {
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-creator', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    $customer = CustomerProfile::factory()->create([
        'address' => 'Customer delivery location',
        'latitude' => 25.2048,
        'longitude' => 55.2708,
    ]);
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);
    // The factory's default variant is a Grain, which is batch-tracked, so the assignment
    // below has to name the batch it draws from.
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'expires_at' => null]);
    fakeStraightLineRouting();

    Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'shipments' => [[
                'warehouse_id' => $warehouse->getKey(),
                'assignments' => [[
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => 2,
                    'inventory_lot_id' => $lot->getKey(),
                ]],
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::query()->sole();
    $delivery = InventoryOperation::query()->whereMorphedTo('sourceDocument', $order)->sole();

    expect($delivery->stage)->toBe(OperationStage::Ready)
        ->and($delivery->delivery_type)->toBe(DeliveryType::Inner)
        ->and($order->delivery_type)->toBe(DeliveryType::Inner->value)
        ->and($delivery->customer_delivery_address_id)->toBeNull()
        ->and($delivery->scheduled_at)->not->toBeNull()
        ->and($delivery->responsible_id)->toBeNull()
        ->and($order->shipments()->sole()->tracking_number)->toStartWith('TRK-')
        ->and($delivery->destination_address_snapshot['address'])->toBe($customer->address)
        ->and((float) $delivery->destination_address_snapshot['latitude'])->toBe(25.2048)
        ->and((float) $delivery->destination_address_snapshot['longitude'])->toBe(55.2708);
});

it('uses the forced operation type for a non-contextual create page', function (): void {
    $actor = contextualDeliveryActor();
    $actor->givePermissionTo([
        Permission::findOrCreate('inventory.receipt.view', 'web'),
        Permission::findOrCreate('inventory.receipt.create', 'web'),
    ]);
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Receipt->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $mutate = new ReflectionMethod(CreateInventoryOperation::class, 'mutateFormDataBeforeCreate');

    expect($component->instance()->hasFormWrapper())->toBeTrue()
        ->and($mutate->invoke($component->instance(), ['notes' => 'Receipt']))
        ->toMatchArray(['operation_type' => OperationType::Receipt->value]);

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

it('stores delivery documents on the delivery created by the contextual wizard', function (): void {
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-documents-creator', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);
    // The factory's default variant is a Grain, which is batch-tracked, so the assignment
    // below has to name the batch it draws from.
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'expires_at' => null]);
    Storage::fake('local');
    fakeStraightLineRouting();

    $documents = [];

    foreach (DeliveryDocument::cases() as $document) {
        $path = UploadedFile::fake()
            ->create($document->value.'.pdf', 200, 'application/pdf')
            ->store('delivery-documents/'.$document->value, 'local');

        if (! is_string($path)) {
            throw new RuntimeException('The fake delivery document could not be stored.');
        }

        $documents[$document->value] = $path;
    }

    Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            ...array_map(static fn (string $path): array => [$path], $documents),
            'shipments' => [[
                'warehouse_id' => $warehouse->getKey(),
                'assignments' => [[
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => 2,
                    'inventory_lot_id' => $lot->getKey(),
                ]],
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $delivery = InventoryOperation::query()->where('operation_type', OperationType::Delivery)->sole();

    foreach (DeliveryDocument::cases() as $document) {
        expect($delivery->getFirstMedia($document->value))->not->toBeNull()
            ->and(Storage::disk('local')->exists($documents[$document->value]))->toBeFalse();
    }
});

it('classifies a shipment as an outer delivery when its route leaves the UAE', function (): void {
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-border-detector', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    Http::fake(['*router.project-osrm.org*' => Http::response([
        'code' => 'Ok',
        'routes' => [[
            'geometry' => [
                'type' => 'LineString',
                // Dips through Muscat, Oman, on the way — outside the UAE boundary.
                'coordinates' => [[55.27, 25.21], [58.3829, 23.5880], [55.2708, 25.2048]],
            ],
        ]],
    ])]);

    Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class)
        ->fillForm(['customer_id' => $customer->getKey()])
        ->fillForm(['shipments' => [['warehouse_id' => $warehouse->getKey()]]])
        ->assertFormSet(['shipments.0.delivery_type' => DeliveryType::Outer->value]);
});

it('persists an automatically generated tracking number for a shipment', function (): void {
    $shipment = Shipment::factory()->create(['tracking_number' => null]);

    expect($shipment->fresh()->tracking_number)
        ->toBe($shipment->tracking_number)
        ->toStartWith('TRK-');
});

it('limits serial number options to available devices in the selected warehouse and requires them for machine products', function (): void {
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-serial-options-viewer', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $otherWarehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $regularVariant = ProductVariant::factory()->create();
    $device = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($warehouse)->create(['status' => SerializedInventoryUnitStatus::Available]);
    $pendingDevice = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($warehouse)->create(['status' => SerializedInventoryUnitStatus::Pending]);
    $otherWarehouseDevice = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($otherWarehouse)->create(['status' => SerializedInventoryUnitStatus::Available]);
    fakeStraightLineRouting();

    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'shipments' => [[
                'warehouse_id' => $warehouse->getKey(),
            ]],
        ]);

    $requiresSerialsMethod = new ReflectionMethod(CreateInventoryOperation::class, 'requiresSerials');
    $serializedUnitOptionsMethod = new ReflectionMethod(CreateInventoryOperation::class, 'serializedUnitOptions');

    expect($requiresSerialsMethod->invoke($component->instance(), $variant->getKey()))->toBeTrue()
        ->and($requiresSerialsMethod->invoke($component->instance(), $regularVariant->getKey()))->toBeFalse()
        ->and($serializedUnitOptionsMethod->invoke($component->instance(), $variant->getKey(), $warehouse->getKey()))
        ->toHaveKey($device->getKey())
        ->not->toHaveKey($pendingDevice->getKey())
        ->not->toHaveKey($otherWarehouseDevice->getKey());
});

it('creates a ready delivery from selected serial numbers for a machine product', function (): void {
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-serial-creator', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->machine()->create();
    $devices = SerializedInventoryUnit::factory()->count(2)->for($variant, 'productVariant')->for($warehouse)->create(['status' => SerializedInventoryUnitStatus::Available]);
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '2.000']);
    fakeStraightLineRouting();

    Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'shipments' => [[
                'warehouse_id' => $warehouse->getKey(),
                'assignments' => [[
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => 2,
                    'serialized_inventory_unit_ids' => $devices->pluck('id')->all(),
                ]],
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::query()->sole();
    $delivery = InventoryOperation::query()->whereMorphedTo('sourceDocument', $order)->sole();

    expect($delivery->stage)->toBe(OperationStage::Ready)
        ->and($delivery->lines)->toHaveCount(2)
        ->and($delivery->lines->pluck('serialized_inventory_unit_id')->sort()->values()->all())
        ->toBe($devices->pluck('id')->sort()->values()->all());
});

it('requires a variant after selecting a product with multiple variants', function (): void {
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('contextual-delivery-variant-creator', 'web');
    $role->givePermissionTo([$createPermission, $viewPermission]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $firstVariant = ProductVariant::factory()->create();
    $secondVariant = ProductVariant::factory()->for($firstVariant->product)->create();
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    InventoryStock::factory()->for($firstVariant)->for($warehouse)->create(['available_quantity' => '10.000']);
    InventoryStock::factory()->for($secondVariant)->for($warehouse)->create(['available_quantity' => '10.000']);
    fakeStraightLineRouting();

    Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'shipments' => [[
                'warehouse_id' => $warehouse->getKey(),
                'assignments' => [[
                    'product_id' => $firstVariant->product_id,
                    'quantity' => 2,
                ]],
            ]],
        ])
        ->call('create')
        ->assertHasFormErrors(['shipments.0.assignments.0.product_variant_id']);
});

it('refuses to build a delivery group without an authenticated actor', function (): void {
    $actor = contextualDeliveryActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    Auth::logout();

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'createDeliveryGroup');

    expect(fn (): InventoryOperation => $method->invoke($component->instance(), ['customer_id' => 1]))
        ->toThrow(NotFoundHttpException::class);
});

it('refuses to build a delivery group without a customer id', function (): void {
    $actor = contextualDeliveryActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'createDeliveryGroup');

    expect(fn (): InventoryOperation => $method->invoke($component->instance(), []))
        ->toThrow(ValidationException::class);
});

it('refuses to build a delivery group for a customer without delivery coordinates', function (): void {
    $actor = contextualDeliveryActor();
    $customer = CustomerProfile::factory()->create(['latitude' => null, 'longitude' => null]);
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'createDeliveryGroup');

    expect(fn (): InventoryOperation => $method->invoke($component->instance(), ['customer_id' => $customer->getKey()]))
        ->toThrow(ValidationException::class);
});

it('refuses to build a delivery group naming a nonexistent responsible user', function (): void {
    $actor = contextualDeliveryActor();
    $customer = CustomerProfile::factory()->create();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'createDeliveryGroup');

    expect(fn (): InventoryOperation => $method->invoke($component->instance(), [
        'customer_id' => $customer->getKey(),
        'responsible_id' => $customer->getKey() + 999_999,
    ]))->toThrow(ValidationException::class);
});

it('refuses to build a delivery group with a non-string scheduled_at value', function (): void {
    $actor = contextualDeliveryActor();
    $customer = CustomerProfile::factory()->create();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'createDeliveryGroup');

    expect(fn (): InventoryOperation => $method->invoke($component->instance(), [
        'customer_id' => $customer->getKey(),
        'scheduled_at' => ['not' => 'a string'],
    ]))->toThrow(ValidationException::class);
});

it('defaults a shipment to an inner delivery when the warehouse has no coordinates on file', function (): void {
    $actor = contextualDeliveryActor();
    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $warehouse = Warehouse::factory()->create(['latitude' => null, 'longitude' => null]);

    Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class)
        ->fillForm(['customer_id' => $customer->getKey()])
        ->fillForm(['shipments' => [['warehouse_id' => $warehouse->getKey()]]])
        ->assertFormSet(['shipments.0.delivery_type' => DeliveryType::Inner->value]);
});

it('shows a stock warning and the no-stock placeholder for a product entirely absent from the warehouse', function (): void {
    $actor = contextualDeliveryActor();
    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->create();
    fakeStraightLineRouting();

    Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'shipments' => [[
                'warehouse_id' => $warehouse->getKey(),
                'assignments' => [[
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => 3,
                ]],
            ]],
        ])
        ->assertSee('No available stock.')
        ->assertSee('Not enough stock')
        ->assertSee('Selected product variant');
});

it('labels a delivery batch option with its expiry date when the lot carries one', function (): void {
    $actor = contextualDeliveryActor();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $expiry = today()->addMonths(3);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'expires_at' => $expiry,
    ]);

    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'lotOptions');
    $options = $method->invoke($component->instance(), $variant->getKey(), $warehouse->getKey());

    expect($options)->toHaveKey($lot->getKey())
        ->and($options[$lot->getKey()])->toContain($expiry->toDateString());
});

it('returns no serial number options when either the variant or the warehouse is missing', function (): void {
    $actor = contextualDeliveryActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'serializedUnitOptions');

    expect($method->invoke($component->instance(), null, 5))->toBe([])
        ->and($method->invoke($component->instance(), 5, null))->toBe([]);
});

it('refuses to render the delivery map without a configured routing service URL', function (): void {
    $actor = contextualDeliveryActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    config(['services.osrm.url' => null]);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'routingServiceUrl');

    expect(fn (): string => $method->invoke($component->instance()))->toThrow(LogicException::class);
});

it('skips malformed shipment and assignment entries while aggregating delivery products', function (): void {
    $actor = contextualDeliveryActor();
    $variant = ProductVariant::factory()->create();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
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
    $actor = contextualDeliveryActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
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
    $actor = contextualDeliveryActor();
    $customer = CustomerProfile::factory()->create([
        'address' => null,
        'city' => '',
        'country' => 'AE',
    ]);
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $summaryMethod = new ReflectionMethod(CreateInventoryOperation::class, 'customerLocationSummary');
    $countryNameMethod = new ReflectionMethod(CreateInventoryOperation::class, 'displayCountryName');

    expect($summaryMethod->invoke($component->instance(), $customer))
        ->toBe($countryNameMethod->invoke($component->instance(), 'AE'));
});

it('resolves a display name for a country code and falls back to the raw value otherwise', function (): void {
    $actor = contextualDeliveryActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'displayCountryName');

    expect($method->invoke($component->instance(), null))->toBeNull()
        ->and($method->invoke($component->instance(), ''))->toBeNull()
        ->and($method->invoke($component->instance(), 'United Arab Emirates'))->toBe('United Arab Emirates')
        ->and($method->invoke($component->instance(), 'XX'))->toBe('XX');
});

it('ignores a non-array shipment entry while collecting selected warehouse ids', function (): void {
    $actor = contextualDeliveryActor();
    $warehouse = Warehouse::factory()->create();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'selectedWarehouseIds');

    expect($method->invoke($component->instance(), ['not-a-shipment', ['warehouse_id' => $warehouse->getKey()]]))
        ->toBe([$warehouse->getKey()]);
});

it('excludes active warehouses missing either delivery coordinate from the map options', function (): void {
    $actor = contextualDeliveryActor();
    Warehouse::factory()->create(['is_active' => true, 'latitude' => null, 'longitude' => 55.27]);
    Warehouse::factory()->create(['is_active' => true, 'latitude' => 25.21, 'longitude' => null]);
    $complete = Warehouse::factory()->create(['is_active' => true, 'latitude' => 25.21, 'longitude' => 55.27]);
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'deliveryMapWarehouseOptions');

    $options = $method->invoke($component->instance());

    expect(collect($options)->pluck('id')->all())->toBe([$complete->getKey()]);
});

it('returns no address for a warehouse id that cannot be resolved', function (): void {
    $actor = contextualDeliveryActor();
    $component = Livewire::withQueryParams(['operation_type' => OperationType::Delivery->value])
        ->actingAs($actor)
        ->test(CreateInventoryOperation::class);

    $method = new ReflectionMethod(CreateInventoryOperation::class, 'warehouseAddress');

    expect($method->invoke($component->instance(), null))->toBeNull()
        ->and($method->invoke($component->instance(), 'not-numeric'))->toBeNull();
});
