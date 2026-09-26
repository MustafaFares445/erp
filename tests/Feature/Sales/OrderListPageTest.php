<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Widgets\OrdersOverview;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

function orderSalesUser(): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::SalesOfficer->value);

    return $user;
}

it('scopes active orders to confirmed and released statuses', function (): void {
    Order::factory()->draft()->create();
    Order::factory()->confirmed()->create();
    Order::factory()->create(['status' => OrderStatus::Released->value]);
    Order::factory()->create(['status' => OrderStatus::Closed->value]);
    Order::factory()->create(['status' => OrderStatus::Cancelled->value]);

    expect(Order::query()->active()->count())->toBe(2);
});

it('scopes awaiting-fulfillment orders to confirmed status', function (): void {
    Order::factory()->confirmed()->create();
    Order::factory()->create(['status' => OrderStatus::Released->value]);

    expect(Order::query()->awaitingFulfillment()->count())->toBe(1);
});

it('scopes blocked orders to released ones with an outstanding procurement requirement', function (): void {
    $variant = ProductVariant::factory()->create();

    $blocked = Order::factory()->create(['status' => OrderStatus::Released->value]);
    $blockedLine = OrderLine::factory()->for($blocked)->create();
    $blocked->procurementRequirements()->create([
        'order_line_id' => $blockedLine->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => 10,
        'fulfilled_base_quantity' => 4,
        'status' => 'pending',
    ]);

    $clear = Order::factory()->create(['status' => OrderStatus::Released->value]);
    $clearLine = OrderLine::factory()->for($clear)->create();
    $clear->procurementRequirements()->create([
        'order_line_id' => $clearLine->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => 10,
        'fulfilled_base_quantity' => 10,
        'status' => 'fulfilled',
    ]);

    expect(Order::query()->blocked()->count())->toBe(1)
        ->and(Order::query()->blocked()->first()?->getKey())->toBe($blocked->getKey());
});

it('renders the orders overview stats widget', function (): void {
    Order::factory()->confirmed()->create();
    Order::factory()->create(['status' => OrderStatus::Released->value]);

    Livewire::actingAs(orderSalesUser())
        ->test(OrdersOverview::class)
        ->assertSuccessful();
});

it('lists orders and filters by customer', function (): void {
    $matching = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $wanted = Order::factory()->for($matching, 'customer')->create();
    $unwanted = Order::factory()->for($other, 'customer')->create();

    Livewire::actingAs(orderSalesUser())
        ->test(ListOrders::class)
        ->filterTable('customer_id', $matching->getKey())
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
});
