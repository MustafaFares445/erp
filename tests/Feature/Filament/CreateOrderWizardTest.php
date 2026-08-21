<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Schemas\Components\Wizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

uses(RefreshDatabase::class);

function createOrderWizardActor(string $roleName): User
{
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $role = Role::findOrCreate($roleName, 'web');
    $role->givePermissionTo([$viewPermission, $createPermission]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function createOrderWizard(Testable $component): Wizard
{
    $schema = $component->instance()->getSchema('form');

    $wizard = collect($schema->getFlatComponents())
        ->first(fn (mixed $candidate): bool => $candidate instanceof Wizard);

    if (! $wizard instanceof Wizard) {
        throw new RuntimeException('Expected the create order form to expose a Wizard component.');
    }

    return $wizard;
}

function createOrderWizardAction(Testable $component, string $name): Action
{
    $schema = $component->instance()->getSchema('form');

    $action = collect($schema->getFlatComponents())
        ->first(fn (mixed $candidate): bool => $candidate instanceof Action && $candidate->getName() === $name);

    if (! $action instanceof Action) {
        throw new RuntimeException(sprintf('Expected the create order form to expose a [%s] action.', $name));
    }

    return $action;
}

it('creates an order with a delivery for the assigned warehouse stock', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-creator');

    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'expires_at' => null,
    ]);

    Livewire::actingAs($actor)
        ->test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'products' => [[
                'product_variant_id' => $variant->getKey(),
                'quantity' => 4,
            ]],
            'shipments' => [[
                'warehouse_id' => $warehouse->getKey(),
                'assignments' => [[
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => 4,
                    'inventory_lot_id' => $lot->getKey(),
                ]],
            ]],
            'notes' => 'Deliver during business hours.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::query()->sole();

    expect($order->customer_id)->toBe($customer->getKey())
        ->and($order->notes)->toBe('Deliver during business hours.')
        ->and($order->lines()->sole()->quantity)->toEqual(4)
        ->and($order->shipments()->sole()->warehouse_id)->toBe($warehouse->getKey());
});

it('rejects order creation when the customer selection cannot be resolved to a real customer', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-invalid-customer');

    Livewire::actingAs($actor)
        ->test(CreateOrder::class)
        ->fillForm([
            'customer_id' => 'not-a-numeric-id',
            'products' => [],
            'shipments' => [],
        ])
        ->call('create');

    expect(Order::query()->count())->toBe(0);
});

it('resets suggested shipments whenever the customer selection changes', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-reset-shipments');

    $firstCustomer = CustomerProfile::factory()->create();
    $secondCustomer = CustomerProfile::factory()->create();

    $component = Livewire::actingAs($actor)->test(CreateOrder::class);

    $component->fillForm(['customer_id' => $firstCustomer->getKey()])
        ->assertFormSet(['shipments' => []]);

    $component->fillForm(['shipments' => [['warehouse_id' => null]]])
        ->fillForm(['customer_id' => $secondCustomer->getKey()])
        ->assertFormSet(['shipments' => []]);
});

it('suggests warehouse shipments after validating the products step and lets the recommendation be refreshed', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-suggest');

    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);
    InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'expires_at' => null,
    ]);

    $component = Livewire::actingAs($actor)
        ->test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'products' => [[
                'product_variant_id' => $variant->getKey(),
                'quantity' => 3,
            ]],
        ]);

    $wizard = createOrderWizard($component);
    $wizard->nextStep(0);
    $wizard->nextStep(1);

    $component->assertFormSet(fn (array $state): bool => ($state['shipments'][0]['warehouse_id'] ?? null) === $warehouse->getKey());

    $rerun = createOrderWizardAction($component, 'rerunWarehouseSelection');
    $rerun->call();

    $component->assertFormSet(fn (array $state): bool => ($state['shipments'][0]['warehouse_id'] ?? null) === $warehouse->getKey());

    $wizard->nextStep(2);
});

it('rejects the products step when there is not enough eligible warehouse stock', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-insufficient-stock');

    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $variant = ProductVariant::factory()->create();

    $component = Livewire::actingAs($actor)
        ->test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'products' => [[
                'product_variant_id' => $variant->getKey(),
                'quantity' => 3,
            ]],
        ]);

    $wizard = createOrderWizard($component);
    $wizard->nextStep(0);

    expect(fn (): mixed => $wizard->nextStep(1))->toThrow(ValidationException::class);
});

it('rejects the warehouse step when the assigned quantity no longer matches the requested demand', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-mismatched-demand');

    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);

    $component = Livewire::actingAs($actor)
        ->test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'products' => [[
                'product_variant_id' => $variant->getKey(),
                'quantity' => 3,
            ]],
            'shipments' => [[
                'warehouse_id' => $warehouse->getKey(),
                'assignments' => [[
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => 1,
                ]],
            ]],
        ]);

    $wizard = createOrderWizard($component);
    $wizard->nextStep(0);
    $wizard->nextStep(1);

    $component->set('data.shipments.0.assignments.0.quantity', 1);

    expect(fn (): mixed => $wizard->nextStep(2))->toThrow(ValidationException::class);
});

it('describes customer delivery locations for every state the wizard can encounter', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-customer-location');
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'customerLocation');

    expect((string) $method->invoke($component->instance(), null))
        ->toContain('Select a customer to load the delivery address.')
        ->and((string) $method->invoke($component->instance(), 999999))
        ->toContain('The selected customer is no longer available.');

    $noAddress = CustomerProfile::factory()->create(['address' => null, 'city' => null, 'country' => null]);
    expect((string) $method->invoke($component->instance(), $noAddress->getKey()))
        ->toContain('No delivery address is recorded for this customer.');

    $noCoordinates = CustomerProfile::factory()->create(['address' => 'Test Street', 'latitude' => null, 'longitude' => null]);
    expect((string) $method->invoke($component->instance(), $noCoordinates->getKey()))
        ->toContain('Add coordinates to enable distance-based warehouse ranking.');

    $withCoordinates = CustomerProfile::factory()->create(['address' => 'Test Street', 'latitude' => 25.2048, 'longitude' => 55.2708]);
    expect((string) $method->invoke($component->instance(), $withCoordinates->getKey()))
        ->toContain('Coordinates are ready for warehouse ranking.');
});

it('summarizes product availability across warehouses and when nothing is in stock', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-availability');
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'availabilitySummary');

    expect((string) $method->invoke($component->instance(), null))
        ->toContain('Select a product to view availability.');

    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '6.000']);

    expect((string) $method->invoke($component->instance(), $variant->getKey()))
        ->toContain('Available')
        ->and((string) $method->invoke($component->instance(), $variant->getKey()))
        ->toContain($warehouse->name);

    $outOfStockVariant = ProductVariant::factory()->create();
    expect((string) $method->invoke($component->instance(), $outOfStockVariant->getKey()))
        ->toContain('Unavailable')
        ->and((string) $method->invoke($component->instance(), $outOfStockVariant->getKey()))
        ->toContain('No warehouse stock available.');
});

it('warns about fulfillment only for products whose demand cannot be met, skipping malformed rows', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-fulfillment-warning');
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'fulfillmentWarning');

    $warehouse = Warehouse::factory()->create();
    $shortVariant = ProductVariant::factory()->create(['name' => 'Scarce Widget']);
    InventoryStock::factory()->for($shortVariant)->for($warehouse)->create(['available_quantity' => '2.000']);

    expect((string) $method->invoke($component->instance(), 'not-an-array'))->toBe('')
        ->and((string) $method->invoke($component->instance(), [
            'not-an-array-row',
            ['product_variant_id' => null, 'quantity' => 5],
            ['product_variant_id' => $shortVariant->getKey(), 'quantity' => 'not-numeric'],
        ]))->toBe('')
        ->and((string) $method->invoke($component->instance(), [
            ['product_variant_id' => $shortVariant->getKey(), 'quantity' => 5],
        ]))->toContain('Fulfillment needs attention');
});

it('summarizes a warehouse route for every combination the wizard can render', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-route-summary');
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'warehouseRouteSummary');

    expect($method->invoke($component->instance(), null, null))
        ->toBe('Select a warehouse to calculate its route.');

    $customer = CustomerProfile::factory()->create(['latitude' => 25.2048, 'longitude' => 55.2708]);
    expect($method->invoke($component->instance(), 999999, $customer->getKey()))
        ->toBe('Route information is unavailable.');

    $noCoordinatesWarehouse = Warehouse::factory()->create(['address' => 'No Coordinates Way', 'latitude' => null, 'longitude' => null]);
    expect($method->invoke($component->instance(), $noCoordinatesWarehouse->getKey(), $customer->getKey()))
        ->toContain('Distance needs map coordinates.');

    $warehouse = Warehouse::factory()->create(['address' => 'Warehouse Road', 'latitude' => 25.2100, 'longitude' => 55.2700]);
    expect($method->invoke($component->instance(), $warehouse->getKey(), $customer->getKey()))
        ->toContain('Warehouse Road')
        ->toContain('km')
        ->toContain('min');
});

it('summarizes warehouse stock for a product, including when nothing is available there', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-stock-summary');
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'warehouseStockSummary');

    expect((string) $method->invoke($component->instance(), null, null))
        ->toContain('Select a product and warehouse.');

    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    expect((string) $method->invoke($component->instance(), $variant->getKey(), $warehouse->getKey()))
        ->toContain('Unavailable');

    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '7.000']);

    expect((string) $method->invoke($component->instance(), $variant->getKey(), $warehouse->getKey()))
        ->toContain('Available')
        ->and((string) $method->invoke($component->instance(), $variant->getKey(), $warehouse->getKey()))
        ->toContain('7.000');
});

it('renders the delivery route preview once a customer and shipments are present', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-route-preview');
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'routePreview');

    expect((string) $method->invoke($component->instance(), null, []))
        ->toContain('Complete the warehouse assignments to preview delivery routes.');

    $customer = CustomerProfile::factory()->create(['company_name' => 'Preview Customer', 'latitude' => 25.2048, 'longitude' => 55.2708]);
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);

    expect((string) $method->invoke($component->instance(), $customer->getKey(), [
        ['warehouse_id' => $warehouse->getKey(), 'assignments' => []],
    ]))->toContain('Preview Customer');
});

it('resolves the active customer behind a shipment or throws when none is selected', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-selected-customer');
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'selectedCustomer');

    expect(fn (): mixed => $method->invoke($component->instance(), null))
        ->toThrow(ValidationException::class);

    $customer = CustomerProfile::factory()->create();
    expect($method->invoke($component->instance(), $customer->getKey()))
        ->toBeInstanceOf(CustomerProfile::class);
});

it('parses integers from both native ints and numeric strings', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-integer-parsing');
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'integer');

    expect($method->invoke($component->instance(), 42))->toBe(42)
        ->and($method->invoke($component->instance(), '42'))->toBe(42)
        ->and($method->invoke($component->instance(), 'not-numeric'))->toBeNull();
});

it('resolves product variant options scoped to the products already assigned to a shipment', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-product-options');
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);

    $productOptionsMethod = new ReflectionMethod(CreateOrder::class, 'productOptions');
    $warehouseOptionsMethod = new ReflectionMethod(CreateOrder::class, 'warehouseOptions');
    $selectedProductOptionsMethod = new ReflectionMethod(CreateOrder::class, 'selectedProductOptions');

    $variant = ProductVariant::factory()->for(Product::factory()->create(['name' => 'Listed Product']))->create();
    $warehouse = Warehouse::factory()->create(['name' => 'Listed Warehouse']);

    expect($productOptionsMethod->invoke($component->instance()))
        ->toHaveKey($variant->getKey())
        ->and($warehouseOptionsMethod->invoke($component->instance()))
        ->toHaveKey($warehouse->getKey())
        ->and($selectedProductOptionsMethod->invoke($component->instance(), [
            ['product_variant_id' => $variant->getKey()],
        ]))->toHaveKey($variant->getKey())
        ->and($selectedProductOptionsMethod->invoke($component->instance(), 'not-an-array'))
        ->toBe([]);
});

it('handles malformed option and shipment state without producing invalid options', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-malformed-state');
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $page = $component->instance();
    $reflection = new ReflectionClass($page);

    $invoke = static function (string $method, array $arguments = []) use ($reflection, $page): mixed {
        $methodReflection = $reflection->getMethod($method);

        return $methodReflection->invokeArgs($page, $arguments);
    };

    expect($invoke('selectedProductOptions', [['not-an-array', ['product_variant_id' => null]]]))->toBe([])
        ->and($invoke('lotOptions', ['not-a-number', null]))->toBe([])
        ->and($invoke('requiresLot', ['not-a-number']))->toBeFalse();

    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $warehouse = Warehouse::factory()->create();
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'lot_number' => null,
        'expires_at' => today()->addMonth(),
        'on_hand_quantity' => '5.000',
        'reserved_quantity' => '0.000',
    ]);

    expect($invoke('lotOptions', [$variant->getKey(), $warehouse->getKey()]))
        ->toHaveKey($lot->getKey());
});

it('rejects order creation without an actor or customer', function (): void {
    $actor = createOrderWizardActor('create-order-wizard-creation-guards');
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'handleRecordCreation');

    auth()->logout();

    expect(fn (): mixed => $method->invoke($component->instance(), []))
        ->toThrow(AccessDeniedHttpException::class);

    $this->actingAs($actor);

    expect(fn (): mixed => $method->invoke($component->instance(), []))
        ->toThrow(ValidationException::class);
});
