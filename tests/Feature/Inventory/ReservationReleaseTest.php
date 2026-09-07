<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\ReservationStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\AuditLog;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryReservationService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

/** @return array{InventoryReservation, InventoryStock, InventoryOperation, User} */
function releasableReservationFixture(): array
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
    $creator = User::factory()->create();

    app(InventoryOperationService::class)->markReady($operation, $creator);

    return [
        InventoryReservation::query()->sole(),
        $stock->refresh(),
        $operation->refresh(),
        $creator,
    ];
}

it('releases a reservation with actor reason evidence audit and restored availability', function (): void {
    [$reservation, $stock] = releasableReservationFixture();
    $actor = User::factory()->create();
    $actor->givePermissionTo(InventoryPermission::ReservationRelease->value);

    expect($stock->available_quantity)->toBe('6.000000');

    app(InventoryReservationService::class)->release(
        $reservation,
        $actor,
        'Customer commitment was cancelled.',
    );

    $released = $reservation->refresh();

    expect($released->status)->toBe(ReservationStatus::Released)
        ->and($released->released_by)->toBe($actor->getKey())
        ->and($released->released_at)->not->toBeNull()
        ->and($released->release_reason)->toBe('Customer commitment was cancelled.')
        ->and($stock->refresh()->on_hand_quantity)->toBe('10.000000')
        ->and($stock->available_quantity)->toBe('10.000000');

    $audit = AuditLog::query()
        ->where('subject_type', InventoryReservation::class)
        ->where('subject_id', $reservation->getKey())
        ->where('description', 'inventory.reservation.released')
        ->sole();

    expect($audit->causer_id)->toBe($actor->getKey())
        ->and($audit->getProperty('reason'))->toBe('Customer commitment was cancelled.');
});

it('refuses manual release without the reservation release permission', function (): void {
    [$reservation] = releasableReservationFixture();

    expect(fn () => app(InventoryReservationService::class)->release(
        $reservation,
        User::factory()->create(),
        'This actor must not be able to release stock.',
    ))->toThrow(AuthorizationException::class);

    expect($reservation->fresh()?->status)->toBe(ReservationStatus::Active);
});

it('requires a reason for a human manual release', function (): void {
    [$reservation] = releasableReservationFixture();
    $actor = User::factory()->create();
    $actor->givePermissionTo(InventoryPermission::ReservationRelease->value);

    expect(fn () => app(InventoryReservationService::class)->release($reservation, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.reservation.errors.reason_required'));

    expect($reservation->fresh()?->status)->toBe(ReservationStatus::Active);
});

it('refuses to release a consumed reservation', function (): void {
    [$reservation, , $operation] = releasableReservationFixture();
    $actor = User::factory()->create();
    $actor->givePermissionTo(InventoryPermission::ReservationRelease->value);

    app(InventoryOperationService::class)->complete($operation, User::factory()->create());

    expect($reservation->fresh()?->status)->toBe(ReservationStatus::Consumed)
        ->and(fn () => app(InventoryReservationService::class)->release(
            $reservation->fresh(),
            $actor,
            'A consumed hold cannot be released.',
        ))->toThrow(DomainException::class, __('admin.inventory.reservation.errors.not_releasable'));
});

it('frees a serialized allocation so the unit can be reserved again', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '1.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '1.000000',
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
        'custody_reference_type' => 'warehouse',
        'custody_reference_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
    ]);

    $first = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $first->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($first, User::factory()->create());

    $second = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $second->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    expect(fn () => $operations->markReady($second, User::factory()->create()))
        ->toThrow(DomainException::class, 'already allocated to an active reservation');

    $reservation = InventoryReservation::query()
        ->where('source_id', $first->getKey())
        ->sole();
    $releaser = User::factory()->create();
    $releaser->givePermissionTo(InventoryPermission::ReservationRelease->value);

    app(InventoryReservationService::class)->release(
        $reservation,
        $releaser,
        'Release the first serialized allocation.',
    );

    $operations->markReady($second->refresh(), User::factory()->create());

    expect(InventoryReservation::query()
        ->where('source_id', $second->getKey())
        ->where('status', ReservationStatus::Active->value)
        ->exists())->toBeTrue()
        ->and($stock->refresh()->reserved_quantity)->toBe('1.000000');
});
