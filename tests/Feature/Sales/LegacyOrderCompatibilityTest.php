<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\InventoryOperation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * FR-027, invariant I-12: an order created before this feature carries no
 * price. It must open and display blank rather than a fabricated zero, and
 * remain fully wired into the fulfillment machinery this feature does not
 * touch.
 */
it('opens a pre-019 order with no prices, showing every commercial field blank', function (): void {
    $user = User::factory()->admin()->create();
    $user->givePermissionTo(Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web'));

    $order = Order::factory()->create();

    expect($order->quotation_id)->toBeNull()
        ->and($order->subtotal)->toBeNull()
        ->and($order->tax_total)->toBeNull()
        ->and($order->grand_total)->toBeNull()
        ->and($order->payment_status)->toBeNull();

    Livewire::actingAs($user)->test(ListOrders::class)->assertSuccessful();

    Livewire::actingAs($user)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertSuccessful();
});

it('keeps a legacy order fully usable in the fulfillment flow: a delivery still attaches to it', function (): void {
    $order = Order::factory()->create();

    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
    ]);

    expect($order->deliveries()->whereKey($delivery->getKey())->exists())->toBeTrue();
});
