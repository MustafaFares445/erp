<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Inventory\InventoryDamageService;
use App\Services\Inventory\InventoryReservationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers active and resolved existing reservation branches', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $actor = User::factory()->create();
    $operation = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $line = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'base_quantity' => '1.000000',
        'serialized_inventory_unit_id' => null,
        'inventory_lot_id' => null,
    ]);
    $reservation = InventoryReservation::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
        'source_line_type' => 'inventory_operation_line',
        'source_line_id' => $line->getKey(),
        'status' => ReservationStatus::Active,
    ]);
    $service = app(InventoryReservationService::class);
    $service->reserveOperation(
        $operation,
        new Collection([$line]),
        (int) $warehouse->getKey(),
        $actor,
    );

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Active);

    $reservation->forceFill([
        'status' => ReservationStatus::Released,
        'released_at' => now(),
    ])->save();

    expect(fn () => $service->reserveOperation(
        $operation,
        new Collection([$line]),
        (int) $warehouse->getKey(),
        $actor,
    ))->toThrow(DomainException::class, 'cannot create a second reservation');
});

it('covers reservation expiry no-op and validation helpers', function (): void {
    $service = app(InventoryReservationService::class);
    $released = InventoryReservation::factory()->create([
        'status' => ReservationStatus::Released,
        'released_at' => now(),
    ]);

    $service->expire($released);

    expect($released->refresh()->status)->toBe(ReservationStatus::Released);
    $lot = new ReflectionMethod(InventoryReservationService::class, 'validatedLotAllocation');
    expect(fn (): mixed => $lot->invoke(
        $service,
        'not-an-integer',
        1,
        '1.000000',
        null,
    ))->toThrow(DomainException::class, 'lot identifiers must be integers');

    $reason = new ReflectionMethod(InventoryReservationService::class, 'manualReleaseReason');
    expect(fn (): mixed => $reason->invoke(
        $service,
        User::factory()->create(),
        str_repeat('x', 256),
    ))->toThrow(DomainException::class);
});

it('covers balance transfer-in and negative adjustment branches', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $service = app(InventoryBalanceService::class);

    $stock = $service->transferIn(
        $variant,
        (int) $warehouse->getKey(),
        2.0,
    );

    expect((float) $stock->on_hand_quantity)->toBe(2.0)
        ->and(fn () => $service->adjustTo(
            $variant,
            (int) $warehouse->getKey(),
            -1.0,
        ))->toThrow(DomainException::class);
});
it('covers numeric-string stock foreign identifiers in damage posting helpers', function (): void {
    $stock = new InventoryStock;
    $stock->forceFill(['product_variant_id' => '42']);

    $method = new ReflectionMethod(InventoryDamageService::class, 'stockForeignId');

    expect($method->invoke(app(InventoryDamageService::class), $stock, 'product_variant_id'))
        ->toBe(42);
});
