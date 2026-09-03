<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Enums\StockCondition;
use App\Events\InventoryReservationExpired;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/** @return array{InventoryStock, ProductVariant, Warehouse} */
function reservationSweepStock(string $onHand, string $reserved): array
{
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $available = bcsub($onHand, $reserved, 6);

    $stock = InventoryStock::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create([
            'on_hand_quantity' => $onHand,
            'reserved_quantity' => $reserved,
            'damaged_quantity' => '0.000000',
            'available_quantity' => $available,
        ]);

    foreach ([
        StockCondition::Saleable => [$onHand, $reserved],
        StockCondition::Quarantine => ['0.000000', '0.000000'],
        StockCondition::Damaged => ['0.000000', '0.000000'],
    ] as $condition => [$conditionOnHand, $conditionReserved]) {
        InventoryConditionBalance::query()->updateOrCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition->value,
        ], [
            'on_hand_base_quantity' => $conditionOnHand,
            'reserved_base_quantity' => $conditionReserved,
        ]);
    }

    return [$stock, $variant, $warehouse];
}

function expiredSweepReservation(
    ProductVariant $variant,
    Warehouse $warehouse,
    string $quantity = '1.000000',
): InventoryReservation {
    return InventoryReservation::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'base_quantity' => $quantity,
        'status' => ReservationStatus::Active,
        'expires_at' => now()->subMinute(),
    ]);
}

it('expires a lapsed active reservation and restores availability without changing on hand', function (): void {
    Event::fake([InventoryReservationExpired::class]);

    [$stock, $variant, $warehouse] = reservationSweepStock('10.000000', '4.000000');
    $reservation = expiredSweepReservation($variant, $warehouse, '4.000000');

    $exit = Artisan::call('inventory:reservations:expire');

    expect($exit)->toBe(0)
        ->and($reservation->fresh()?->status)->toBe(ReservationStatus::Expired)
        ->and($reservation->fresh()?->released_by)->toBeNull()
        ->and($reservation->fresh()?->release_reason)->toBeNull()
        ->and($stock->refresh()->on_hand_quantity)->toBe('10.000000')
        ->and($stock->reserved_quantity)->toBe('0.000000')
        ->and($stock->available_quantity)->toBe('10.000000');

    Event::assertDispatchedTimes(InventoryReservationExpired::class, 1);
});

it('leaves future and already resolved reservations untouched', function (): void {
    Event::fake([InventoryReservationExpired::class]);

    $future = InventoryReservation::factory()->create([
        'status' => ReservationStatus::Active,
        'expires_at' => now()->addHour(),
    ]);
    $consumed = InventoryReservation::factory()->create([
        'status' => ReservationStatus::Consumed,
        'expires_at' => now()->subHour(),
        'consumed_at' => now()->subMinutes(30),
    ]);
    $released = InventoryReservation::factory()->create([
        'status' => ReservationStatus::Released,
        'expires_at' => now()->subHour(),
        'released_at' => now()->subMinutes(30),
    ]);

    expect(Artisan::call('inventory:reservations:expire'))->toBe(0)
        ->and($future->fresh()?->status)->toBe(ReservationStatus::Active)
        ->and($consumed->fresh()?->status)->toBe(ReservationStatus::Consumed)
        ->and($released->fresh()?->status)->toBe(ReservationStatus::Released);

    Event::assertNotDispatched(InventoryReservationExpired::class);
});

it('processes more than one expiry chunk', function (): void {
    Event::fake([InventoryReservationExpired::class]);

    [$stock, $variant, $warehouse] = reservationSweepStock('600.000000', '600.000000');

    InventoryReservation::factory()
        ->count(600)
        ->create([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'base_quantity' => '1.000000',
            'status' => ReservationStatus::Active,
            'expires_at' => now()->subMinute(),
        ]);

    $exit = Artisan::call('inventory:reservations:expire');

    expect($exit)->toBe(0)
        ->and(InventoryReservation::query()
            ->where('status', ReservationStatus::Expired->value)
            ->count())->toBe(600)
        ->and($stock->refresh()->reserved_quantity)->toBe('0.000000')
        ->and($stock->available_quantity)->toBe('600.000000');

    Event::assertDispatchedTimes(InventoryReservationExpired::class, 600);
});

it('continues after one reservation fails and returns a non-zero exit code', function (): void {
    Event::fake([InventoryReservationExpired::class]);

    $broken = InventoryReservation::factory()->create([
        'status' => ReservationStatus::Active,
        'expires_at' => now()->subMinutes(2),
    ]);

    [$stock, $variant, $warehouse] = reservationSweepStock('2.000000', '1.000000');
    $valid = expiredSweepReservation($variant, $warehouse);

    $exit = Artisan::call('inventory:reservations:expire');

    expect($exit)->not->toBe(0)
        ->and($broken->fresh()?->status)->toBe(ReservationStatus::Active)
        ->and($valid->fresh()?->status)->toBe(ReservationStatus::Expired)
        ->and($stock->refresh()->reserved_quantity)->toBe('0.000000');

    Event::assertDispatchedTimes(InventoryReservationExpired::class, 1);
});
