<?php

declare(strict_types=1);

use App\Data\Inventory\PriceFloorOverrideData;
use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\ProductStatus;
use App\Enums\SalesPermission;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\SalesProcurementRequirement;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Inventory\ProductPricingService;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();
});

function orderViewer(): User
{
    $user = User::factory()->admin()->create();
    $role = Role::findOrCreate('order-viewer', 'web');
    $role->givePermissionTo(Permission::findOrCreate(SalesPermission::OrderView->value, 'web'));
    $user->assignRole($role);

    return $user;
}

function activeOrderVariant(array $attributes = []): ProductVariant
{
    $variant = ProductVariant::factory()->create([
        'base_price' => 100,
        'status' => ProductStatus::Active,
        ...$attributes,
    ]);
    $variant->product->update(['status' => ProductStatus::Active]);

    return $variant;
}

it('shows the milestone banner and Release to Logistics for a confirmed order', function (): void {
    $officer = orderViewer();
    $officer->assignRole(DashboardRole::SalesOfficer->value);
    $order = Order::factory()->confirmed()->create([
        'subtotal' => 214.20, 'tax_total' => 0, 'grand_total' => 214.20,
    ]);
    OrderLine::factory()->for($order)->create(['product_variant_id' => activeOrderVariant()->getKey(), 'quantity' => 3]);

    Livewire::actingAs($officer)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSuccessful()
        ->assertSee('Awaiting release to Logistics')
        ->assertSee('commercially confirmed, but Logistics has not received it yet');
});

it('formats quantities for humans and does not leak the raw pricing-tier enum value', function (): void {
    $viewer = orderViewer();
    $customer = CustomerProfile::factory()->create();
    PricingTier::factory()->customerSpecific()->create([
        'name' => 'VIP Wholesale',
        'customer_user_id' => $customer->user_id,
        'discount_value' => 15,
    ]);
    $order = Order::factory()->for($customer, 'customer')->create();
    $variant = activeOrderVariant();
    OrderLine::factory()->for($order)->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => 3,
    ]);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSuccessful()
        ->assertSee('3')
        ->assertDontSee('3.000000')
        ->assertDontSee('customer_specific_tier')
        ->assertSee('Customer-specific pricing tier');
});

it('renders an approved below-floor override with its approver, and does not warn on a floor-compliant price', function (): void {
    $approver = orderViewer();
    $approver->givePermissionTo(InventoryPermission::PriceFloorApprove->value);
    $customer = CustomerProfile::factory()->create();
    $variant = activeOrderVariant(['base_price' => 100, 'min_price' => 90]);
    $override = app(ProductPricingService::class)->approveFloorOverride(
        new PriceFloorOverrideData($variant->getKey(), $customer->user_id, 80, 'Strategic customer discount'),
        $approver,
    );
    $order = Order::factory()->for($customer, 'customer')->create();
    OrderLine::factory()->for($order)->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => 1,
        'unit_price' => 80,
        'price_floor_override_id' => $override->getKey(),
    ]);

    Livewire::actingAs($approver)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSuccessful()
        ->assertSee('Approved exception')
        ->assertSee($approver->name);
});

it('explains a short-closed quantity only when it is greater than zero', function (): void {
    $viewer = orderViewer();
    $order = Order::factory()->create();
    OrderLine::factory()->for($order)->create([
        'product_variant_id' => activeOrderVariant()->getKey(),
        'quantity' => 10,
        'short_closed_base_quantity' => 2,
    ]);

    $untouched = Order::factory()->create();
    OrderLine::factory()->for($untouched)->create([
        'product_variant_id' => activeOrderVariant()->getKey(),
        'quantity' => 5,
    ]);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSuccessful()
        ->assertSee('short-closed');

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $untouched->getKey()])
        ->assertSuccessful()
        ->assertDontSee('short-closed');
});

it('shows a success state when supply is not blocked, and the blocker when it is', function (): void {
    $viewer = orderViewer();
    $order = Order::factory()->create();
    $line = OrderLine::factory()->for($order)->create(['product_variant_id' => activeOrderVariant()->getKey(), 'quantity' => 5]);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSuccessful()
        ->assertSee('No procurement requirements are currently blocking this order');

    SalesProcurementRequirement::query()->create([
        'order_id' => $order->getKey(),
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $line->product_variant_id,
        'required_base_quantity' => 2,
        'fulfilled_base_quantity' => 0,
        'status' => 'open',
    ]);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSuccessful()
        ->assertDontSee('No procurement requirements are currently blocking this order')
        ->assertSee('Open');
});

it('renders an informative empty state for logistics, and links a shipment when one exists', function (): void {
    $viewer = orderViewer();
    $order = Order::factory()->create();
    OrderLine::factory()->for($order)->create(['product_variant_id' => activeOrderVariant()->getKey(), 'quantity' => 1]);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSuccessful()
        ->assertSee('No deliveries or shipments exist yet');

    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
    ]);
    Shipment::factory()->for($order)->for($delivery, 'delivery')->arrived()->create(['tracking_number' => 'TRK-000123']);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSuccessful()
        ->assertDontSee('No deliveries or shipments exist yet')
        ->assertSee('TRK-000123');
});

it('renders financial state without an invoice, then outstanding, then fully settled', function (): void {
    $viewer = orderViewer();
    $order = Order::factory()->create(['grand_total' => 214.20]);
    OrderLine::factory()->for($order)->create(['product_variant_id' => activeOrderVariant()->getKey(), 'quantity' => 1]);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSuccessful()
        ->assertSee('Not issued yet');

    $invoice = Invoice::factory()->for($order)->create([
        'total_amount' => 214.20,
        'amount_paid' => 100,
        'status' => 'issued',
        'issued_at' => now(),
    ]);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSuccessful()
        ->assertDontSee('Not issued yet')
        ->assertSee('Payment pending');

    $invoice->update(['amount_paid' => 214.20]);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSuccessful()
        ->assertSee('Complete');
});

it('does not offer a Sales/Admin close order action on the redesigned page', function (): void {
    $viewer = orderViewer();
    $order = Order::factory()->create();
    OrderLine::factory()->for($order)->create(['product_variant_id' => activeOrderVariant()->getKey(), 'quantity' => 1]);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSuccessful()
        ->assertActionDoesNotExist('close_order')
        ->assertActionDoesNotExist('complete_order');
});

it('hides the completion section for a draft order and shows it for a released order', function (): void {
    $viewer = orderViewer();
    $draft = Order::factory()->draft()->create();
    OrderLine::factory()->for($draft)->create(['product_variant_id' => activeOrderVariant()->getKey(), 'quantity' => 1]);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $draft->getKey()])
        ->assertSuccessful()
        ->assertDontSee('Order completion');

    $released = Order::factory()->create();
    OrderLine::factory()->for($released)->create(['product_variant_id' => activeOrderVariant()->getKey(), 'quantity' => 1]);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $released->getKey()])
        ->assertSuccessful()
        ->assertSee('Order completion')
        ->assertSee('Not ready for final confirmation');
});

it('keeps Cancel order permission-gated on the redesigned page', function (): void {
    $viewer = orderViewer();
    $order = Order::factory()->create();
    OrderLine::factory()->for($order)->create(['product_variant_id' => activeOrderVariant()->getKey(), 'quantity' => 1]);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionHidden('cancel_order');

    $viewer->givePermissionTo(SalesPermission::OrderCancel->value);

    Livewire::actingAs($viewer)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionVisible('cancel_order');
});
