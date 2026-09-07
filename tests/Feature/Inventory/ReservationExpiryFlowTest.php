<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryLotReconciliationService;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('flags an order as lapsed after its delivery reservation expires and keeps reconciliation clean', function (): void {
    $order = Order::factory()->create();
    $quotation = Quotation::factory()->accepted()->create([
        'customer_id' => $order->customer_id,
        'converted_order_id' => $order->getKey(),
    ]);
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '10.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);

    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
        'customer_id' => $order->customer_id,
    ]);
    $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '4',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    app(InventoryOperationService::class)->markReady(
        $delivery,
        User::factory()->create(),
    );

    $reservation = InventoryReservation::query()
        ->where('source_type', 'inventory_operation')
        ->where('source_id', $delivery->getKey())
        ->sole();

    expect($reservation->status)->toBe(ReservationStatus::Active)
        ->and($stock->refresh()->available_quantity)->toBe('6.000000')
        ->and($order->fresh()->hasLapsedReservations())->toBeFalse()
        ->and($quotation->fresh()->hasLapsedReservations())->toBeFalse();

    $reservation->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(Artisan::call('inventory:reservations:expire'))->toBe(0);

    $order->refresh();

    expect($reservation->fresh()?->status)->toBe(ReservationStatus::Expired)
        ->and($stock->refresh()->on_hand_quantity)->toBe('10.000000')
        ->and($stock->available_quantity)->toBe('10.000000')
        ->and($order->hasLapsedReservations())->toBeTrue()
        ->and($quotation->fresh()->hasLapsedReservations())->toBeTrue()
        ->and(app(InventoryLotReconciliationService::class)->inspect()['errors'])->toBe([]);

    expect(AuditLog::query()
        ->where('subject_type', Order::class)
        ->where('subject_id', $order->getKey())
        ->where('description', 'inventory.reservation.expired')
        ->exists())->toBeTrue();
});
