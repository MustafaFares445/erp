<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\InventoryReservationAllocation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates source-linked allocations and reconciles active reservations with reserved stock', function (): void {
    [$operation, $stock, $lot, $actor] = reservationFixture();

    app(InventoryOperationService::class)->markReady($operation, $actor);

    $reservation = InventoryReservation::query()->sole();
    $allocation = InventoryReservationAllocation::query()->sole();

    expect($operation->refresh()->stage->value)->toBe('ready')
        ->and($reservation->status)->toBe(ReservationStatus::Active)
        ->and($reservation->source_type)->toBe('inventory_operation')
        ->and($reservation->source_id)->toBe($operation->getKey())
        ->and($reservation->source_line_type)->toBe('inventory_operation_line')
        ->and($reservation->base_quantity)->toBe('4.000000')
        ->and($allocation->inventory_lot_id)->toBe($lot->getKey())
        ->and($allocation->base_quantity)->toBe('4.000000')
        ->and($stock->refresh()->reserved_quantity)->toBe('4.000000')
        ->and($lot->refresh()->reserved_quantity)->toBe('4.000000')
        ->and((float) InventoryReservationAllocation::query()
            ->whereHas('reservation', fn ($query) => $query->where('status', ReservationStatus::Active->value))
            ->sum('base_quantity'))->toBe(4.0);
});

it('consumes the reservation once when a delivery posts', function (): void {
    [$operation, $stock, $lot, $actor] = reservationFixture();

    $service = app(InventoryOperationService::class);
    $service->markReady($operation, $actor);
    $service->complete($operation->refresh(), $actor);

    $reservation = InventoryReservation::query()->sole();

    expect($reservation->status)->toBe(ReservationStatus::Consumed)
        ->and($reservation->consumed_at)->not->toBeNull()
        ->and($stock->refresh()->on_hand_quantity)->toBe('6.000000')
        ->and($stock->reserved_quantity)->toBe('0.000000')
        ->and($lot->refresh()->on_hand_quantity)->toBe('6.000000')
        ->and($lot->reserved_quantity)->toBe('0.000000')
        ->and(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe(1);
});

it('releases a ready reservation and lot allocation on cancellation', function (): void {
    [$operation, $stock, $lot, $actor] = reservationFixture();

    $service = app(InventoryOperationService::class);
    $service->markReady($operation, $actor);
    $service->cancel($operation->refresh(), $actor, 'No longer required');

    $reservation = InventoryReservation::query()->sole();

    expect($reservation->status)->toBe(ReservationStatus::Released)
        ->and($reservation->released_at)->not->toBeNull()
        ->and($stock->refresh()->on_hand_quantity)->toBe('10.000000')
        ->and($stock->reserved_quantity)->toBe('0.000000')
        ->and($lot->refresh()->on_hand_quantity)->toBe('10.000000')
        ->and($lot->reserved_quantity)->toBe('0.000000');
});

it('expires an active reservation and releases its materialized allocation', function (): void {
    [$operation, $stock, $lot, $actor] = reservationFixture();

    app(InventoryOperationService::class)->markReady($operation, $actor);
    $reservation = InventoryReservation::query()->sole();

    app(InventoryReservationService::class)->expire($reservation, $actor);

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Expired)
        ->and($stock->refresh()->reserved_quantity)->toBe('0.000000')
        ->and($lot->refresh()->reserved_quantity)->toBe('0.000000');
});

/** @return array{InventoryOperation, InventoryStock, InventoryLot, User} */
function reservationFixture(): array
{
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
    $operation = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '4',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    return [$operation, $stock, $lot, User::factory()->create()];
}
