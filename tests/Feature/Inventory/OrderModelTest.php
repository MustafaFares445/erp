<?php

declare(strict_types=1);

use App\Models\CustomerDeliveryAddress;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the delivery address relation', function (): void {
    $deliveryAddress = CustomerDeliveryAddress::factory()->create();
    $order = Order::factory()->create([
        'customer_id' => $deliveryAddress->customer_profile_id,
        'customer_delivery_address_id' => $deliveryAddress->getKey(),
    ]);

    expect($order->deliveryAddress()->first()->is($deliveryAddress))->toBeTrue();
});
