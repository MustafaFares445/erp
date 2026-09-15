<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\SalesPermission;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function salesOrderWizardActor(bool $canConfirm = false): User
{
    $role = Role::findOrCreate('sales-order-wizard-'.($canConfirm ? 'confirmer' : 'creator'), 'web');
    $permissions = [
        Permission::findOrCreate(SalesPermission::OrderView->value, 'web'),
        Permission::findOrCreate(SalesPermission::OrderCreate->value, 'web'),
    ];

    if ($canConfirm) {
        $permissions[] = Permission::findOrCreate(SalesPermission::OrderConfirm->value, 'web');
    }

    $role->givePermissionTo($permissions);

    $user = User::factory()->admin()->create();
    $user->assignRole($role);

    return $user;
}

function pricedSalesVariant(float $price = 100): ProductVariant
{
    return ProductVariant::factory()->create([
        'base_price' => $price,
        'min_price' => max(0, $price - 20),
    ]);
}

it('creates only a commercial draft and leaves fulfillment to logistics', function (): void {
    $actor = salesOrderWizardActor();
    $customer = CustomerProfile::factory()->create();
    $address = CustomerDeliveryAddress::factory()->for($customer, 'customer')->create([
        'label' => 'Main Clinic',
        'address' => '10 Dental Street',
        'city' => 'Dubai',
    ]);
    $variant = pricedSalesVariant(125);

    Livewire::actingAs($actor)
        ->test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'customer_delivery_address_id' => $address->getKey(),
            'lines' => [[
                'product_variant_id' => $variant->getKey(),
                'unit_id' => $variant->unit_id,
                'quantity' => 4,
            ]],
            'notes' => 'Deliver during business hours.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::query()->sole();
    $line = $order->lines()->sole();

    expect($order->status)->toBe(OrderStatus::Draft)
        ->and($order->customer_id)->toBe($customer->getKey())
        ->and($order->customer_delivery_address_id)->toBe($address->getKey())
        ->and($order->destination_address_snapshot['address'] ?? null)->toBe('10 Dental Street')
        ->and($order->notes)->toBe('Deliver during business hours.')
        ->and((float) $line->quantity)->toBe(4.0)
        ->and((float) $line->transaction_quantity)->toBe(4.0)
        ->and((float) $line->base_quantity)->toBe(4.0)
        ->and((float) $line->unit_price)->toBe(125.0)
        ->and($order->deliveries()->count())->toBe(0)
        ->and($order->shipments()->count())->toBe(0);
});

it('can save and confirm the commercial order when the actor has confirm permission', function (): void {
    $actor = salesOrderWizardActor(true);
    $customer = CustomerProfile::factory()->create();
    $variant = pricedSalesVariant();

    Livewire::actingAs($actor)
        ->test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'lines' => [[
                'product_variant_id' => $variant->getKey(),
                'unit_id' => $variant->unit_id,
                'quantity' => 2,
            ]],
            'confirm_now' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Order::query()->sole()->status)->toBe(OrderStatus::Confirmed);
});

it('does not allow an address that belongs to another customer', function (): void {
    $actor = salesOrderWizardActor();
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    $otherAddress = CustomerDeliveryAddress::factory()->for($otherCustomer, 'customer')->create();
    $variant = pricedSalesVariant();

    Livewire::actingAs($actor)
        ->test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'lines' => [[
                'product_variant_id' => $variant->getKey(),
                'unit_id' => $variant->unit_id,
                'quantity' => 1,
            ]],
        ])
        ->set('data.customer_delivery_address_id', $otherAddress->getKey())
        ->call('create')
        ->assertHasFormErrors(['customer_delivery_address_id']);

    expect(Order::query()->count())->toBe(0);
});

it('lists only active products and active variants in the product selector', function (): void {
    $actor = salesOrderWizardActor();
    $visible = pricedSalesVariant();
    $inactiveVariant = ProductVariant::factory()->create(['is_active' => false, 'base_price' => 50]);
    $inactiveProduct = Product::factory()->create(['is_active' => false]);
    $hiddenByProduct = ProductVariant::factory()->for($inactiveProduct, 'product')->create(['base_price' => 60]);
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'productOptions');

    /** @var array<int, string> $options */
    $options = $method->invoke($component->instance());

    expect($options)->toHaveKey($visible->getKey())
        ->and($options)->not->toHaveKey($inactiveVariant->getKey())
        ->and($options)->not->toHaveKey($hiddenByProduct->getKey());
});

it('scopes delivery address choices to the selected customer and active rows', function (): void {
    $actor = salesOrderWizardActor();
    $customer = CustomerProfile::factory()->create();
    $active = CustomerDeliveryAddress::factory()->for($customer, 'customer')->create();
    $inactive = CustomerDeliveryAddress::factory()->for($customer, 'customer')->create(['is_active' => false]);
    $other = CustomerDeliveryAddress::factory()->create();
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'deliveryAddressOptions');

    expect($method->invoke($component->instance(), 'not-an-id'))->toBe([]);

    /** @var array<int, string> $options */
    $options = $method->invoke($component->instance(), $customer->getKey());

    expect($options)->toHaveKey($active->getKey())
        ->and($options)->not->toHaveKey($inactive->getKey())
        ->and($options)->not->toHaveKey($other->getKey());
});

it('resolves sale units and falls back to the variant base unit', function (): void {
    $actor = salesOrderWizardActor();
    $variant = pricedSalesVariant();
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $optionsMethod = new ReflectionMethod(CreateOrder::class, 'saleUnitOptions');
    $defaultMethod = new ReflectionMethod(CreateOrder::class, 'defaultSaleUnitId');

    expect($optionsMethod->invoke($component->instance(), null))->toBe([])
        ->and($defaultMethod->invoke($component->instance(), null))->toBeNull();

    /** @var array<int, string> $options */
    $options = $optionsMethod->invoke($component->instance(), $variant->getKey());
    expect($options)->toHaveKey($variant->unit_id)
        ->and($defaultMethod->invoke($component->instance(), $variant->getKey()))->toBe($variant->unit_id);

    $variant->variantUnits()->update(['is_active' => false]);

    /** @var array<int, string> $fallback */
    $fallback = $optionsMethod->invoke($component->instance(), $variant->getKey());
    expect($fallback)->toHaveKey($variant->unit_id)
        ->and($defaultMethod->invoke($component->instance(), $variant->getKey()))->toBe($variant->unit_id);
});

it('shows advisory inventory availability without reserving stock', function (): void {
    $actor = salesOrderWizardActor();
    $variant = pricedSalesVariant();
    $warehouseA = Warehouse::factory()->create();
    $warehouseB = Warehouse::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouseA)->create(['available_quantity' => '3.250']);
    InventoryStock::factory()->for($variant)->for($warehouseB)->create(['available_quantity' => '2.750']);
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'availabilityPreview');

    expect($method->invoke($component->instance(), null))->toBe('Select a product')
        ->and($method->invoke($component->instance(), $variant->getKey()))->toBe('6.000000 base units');
});

it('previews resolved pricing and handles missing selections', function (): void {
    $actor = salesOrderWizardActor();
    $customer = CustomerProfile::factory()->create();
    $variant = pricedSalesVariant(88.50);
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'pricePreview');

    expect($method->invoke($component->instance(), $customer->getKey(), null, null))->toBe('Select a product')
        ->and($method->invoke($component->instance(), $customer->getKey(), 999999, null))->toBe('Unavailable')
        ->and((string) $method->invoke($component->instance(), $customer->getKey(), $variant->getKey(), $variant->unit_id))
        ->toContain('88.50')
        ->toContain('base');
});

it('normalizes line state defensively', function (): void {
    $method = new ReflectionMethod(CreateOrder::class, 'normalizeLineState');

    expect($method->invoke(null, ['quantity' => 2]))->toBe(['quantity' => 2]);
    expect(fn (): mixed => $method->invoke(null, 'invalid'))->toThrow(LogicException::class);
    expect(fn (): mixed => $method->invoke(null, [0 => 'invalid-key']))->toThrow(LogicException::class);
});

it('returns null for a missing address and rejects inactive addresses', function (): void {
    $actor = salesOrderWizardActor();
    $customer = CustomerProfile::factory()->create();
    $inactive = CustomerDeliveryAddress::factory()->for($customer, 'customer')->create(['is_active' => false]);
    $component = Livewire::actingAs($actor)->test(CreateOrder::class);
    $method = new ReflectionMethod(CreateOrder::class, 'deliveryAddress');

    expect($method->invoke($component->instance(), null, $customer->getKey()))->toBeNull();
    expect(fn (): mixed => $method->invoke($component->instance(), $inactive->getKey(), $customer->getKey()))
        ->toThrow(ValidationException::class);
});
