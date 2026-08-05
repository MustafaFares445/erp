<?php

declare(strict_types=1);

use App\Enums\DeliveryDocument;
use App\Enums\DeliveryType;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryOperations\Pages\CreateInventoryOperation;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function fakeStraightLineRouting(): void
{
    Http::fake(fn (): Response => Http::response(['code' => 'NoRoute', 'routes' => []]));
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
    $productOptionsMethod->setAccessible(true);
    $warehouseOptionsMethod->setAccessible(true);
    $availableQuantityMethod->setAccessible(true);

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
        ->call('create')
        ->assertHasFormErrors(['shipments.0.assignments.0.quantity']);
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
        ->toContain("className: 'customer-delivery-map__label'");
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
        ->and($delivery->scheduled_at)->toBeNull()
        ->and($delivery->responsible_id)->toBeNull()
        ->and($order->shipments()->sole()->tracking_number)->toStartWith('TRK-')
        ->and($delivery->destination_address_snapshot['address'])->toBe($customer->address)
        ->and((float) $delivery->destination_address_snapshot['latitude'])->toBe(25.2048)
        ->and((float) $delivery->destination_address_snapshot['longitude'])->toBe(55.2708);
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
