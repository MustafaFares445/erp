<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function guardsService(): InventoryOperationService
{
    return app(InventoryOperationService::class);
}

// FR-004, FR-006, SRS §4: available quantity must never go negative. An outbound operation that
// cannot be satisfied is held at Waiting, naming the product and the shortfall.

it('holds an outbound operation at Waiting when available stock is insufficient, changing no balance', function (): void {
    $source = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($source)->create(['on_hand_quantity' => '2.000', 'available_quantity' => '2.000']);
    $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '5.000', 'unit_id' => $variant->unit_id]);

    guardsService()->markReady($operation);

    expect($operation->refresh()->isWaiting())->toBeTrue()
        ->and((float) InventoryStock::query()->where('product_variant_id', $variant->getKey())->where('warehouse_id', $source->getKey())->value('on_hand_quantity'))->toBe(2.0);
});

it('recovers from Waiting to Ready once enough stock becomes available', function (): void {
    $source = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($source)->create(['on_hand_quantity' => '2.000', 'available_quantity' => '2.000']);
    $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '5.000', 'unit_id' => $variant->unit_id]);
    guardsService()->markReady($operation);
    expect($operation->refresh()->isWaiting())->toBeTrue();

    $stock->forceFill(['on_hand_quantity' => '10.000', 'available_quantity' => '10.000'])->save();
    guardsService()->markReady($operation->refresh());

    expect($operation->refresh()->isReady())->toBeTrue();
});

// FR-008: Done is immutable and undeletable; the caller is directed to a correcting operation.

it('refuses to authorize update or delete on a Done operation', function (): void {
    $operation = InventoryOperation::factory()->receipt()->done()->create();
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::ReceiptCreate->value);

    expect($user->can('update', $operation))->toBeFalse()
        ->and($user->can('delete', $operation))->toBeFalse();
});

// G-5: concurrent confirmation of the same operation — exactly one succeeds, the loser is told
// it is already processed. Simulated by re-invoking complete() after it has already landed on
// Done, which is exactly the state the loser of a real race observes once it acquires the lock.

it('tells a second confirmation attempt the operation was already processed', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '1.000', 'unit_id' => $variant->unit_id]);
    $actor = User::factory()->create();

    guardsService()->markReady($operation);
    guardsService()->complete($operation->refresh(), $actor);

    expect(fn (): InventoryOperation => guardsService()->complete($operation->refresh(), $actor))
        ->toThrow(DomainException::class, __('admin.inventory.operation.errors.already_processed'));
});

it('cancels a Ready outbound operation and releases its reservation', function (): void {
    $source = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($source)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '3.000',
        'available_quantity' => '7.000',
    ]);
    $operation = InventoryOperation::factory()->delivery()->ready()->create(['source_warehouse_id' => $source->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '3.000', 'unit_id' => $variant->unit_id]);

    guardsService()->cancel($operation, User::factory()->create(), 'No longer required');

    expect($operation->refresh()->isCanceled())->toBeTrue()
        ->and((float) $stock->refresh()->reserved_quantity)->toBe(0.0)
        ->and((float) $stock->available_quantity)->toBe(10.0);
});

it('restores source custody when an InTransit transfer is canceled', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($source)->create([
        'on_hand_quantity' => '7.000',
        'available_quantity' => '7.000',
    ]);
    $operation = InventoryOperation::factory()->internalTransfer()->inTransit()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '3.000', 'unit_id' => $variant->unit_id]);

    guardsService()->cancel($operation, User::factory()->create(), 'Transfer recalled');

    expect($operation->refresh()->isCanceled())->toBeTrue()
        ->and((float) $stock->refresh()->on_hand_quantity)->toBe(10.0)
        ->and((float) InventoryMovement::query()->where('source_id', $operation->getKey())->sum('quantity'))->toBe(3.0);
});

it('rejects transitions and previews when a required warehouse is missing', function (): void {
    $service = guardsService();
    $actor = User::factory()->create();
    $transfer = InventoryOperation::factory()->internalTransfer()->ready()->create(['source_warehouse_id' => null]);
    $receipt = InventoryOperation::factory()->receipt()->ready()->create(['destination_warehouse_id' => null]);
    $delivery = InventoryOperation::factory()->delivery()->ready()->create(['source_warehouse_id' => null]);

    expect(fn (): InventoryOperation => $service->dispatch($transfer, $actor))
        ->toThrow(DomainException::class)
        ->and(fn (): InventoryOperation => $service->complete($receipt, $actor))
        ->toThrow(DomainException::class)
        ->and(fn (): InventoryOperation => $service->complete($delivery, $actor))
        ->toThrow(DomainException::class)
        ->and($service->previewEffect($delivery))->toBe([]);
});

it('ignores nonnumeric legacy variant keys in reservation guards', function (): void {
    $service = guardsService();
    $line = new InventoryOperationLine;
    $line->setRawAttributes(['product_variant_id' => 'legacy', 'quantity' => '1.000']);

    $lines = new Collection([$line]);

    expect(new ReflectionMethod($service, 'firstInsufficientVariant')->invoke($service, $lines, 1))->toBeNull()
        ->and(new ReflectionMethod($service, 'reserveLines')->invoke($service, $lines, 1))->toBeNull()
        ->and(new ReflectionMethod($service, 'releaseReservations')->invoke($service, $lines, 1))->toBeNull();
});

it('tells a second markReady() attempt on a canceled operation it was already processed', function (): void {
    $operation = InventoryOperation::factory()->receipt()->canceled()->create();

    expect(fn (): InventoryOperation => guardsService()->markReady($operation))
        ->toThrow(DomainException::class, __('admin.inventory.operation.errors.already_processed'));
});

// SRS §3.4, §4 (V-09): a serialized unit may appear on at most one non-canceled operation line.

it('rejects a serial number already recorded on another non-canceled operation line', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $serializedUnit = SerializedInventoryUnit::factory()->create(['product_variant_id' => $variant->getKey()]);

    $firstOperation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $firstOperation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $serializedUnit->getKey(),
    ]);

    $secondOperation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $secondOperation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $serializedUnit->getKey(),
    ]);

    expect(fn (): InventoryOperation => guardsService()->markReady($secondOperation))
        ->toThrow(DomainException::class);
});

it('allows a serial number to be reused once its earlier operation line is canceled', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $serializedUnit = SerializedInventoryUnit::factory()->create(['product_variant_id' => $variant->getKey()]);

    $firstOperation = InventoryOperation::factory()->receipt()->canceled()->create(['destination_warehouse_id' => $destination->getKey()]);
    $firstOperation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $serializedUnit->getKey(),
    ]);

    $secondOperation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $secondOperation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $serializedUnit->getKey(),
    ]);

    guardsService()->markReady($secondOperation);

    expect($secondOperation->refresh()->isReady())->toBeTrue();
});

// SRS §3.3 (V-07): an operation cannot leave Draft while a line's product is Inactive or Coming
// Soon.

it('blocks an operation from leaving Draft while a line references an inactive product', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['is_active' => false]);
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '1.000', 'unit_id' => $variant->unit_id]);

    expect(fn (): InventoryOperation => guardsService()->markReady($operation))
        ->toThrow(DomainException::class, __('admin.inventory.operation.errors.inactive_variant'));

    expect($operation->refresh()->isDraft())->toBeTrue();
});

// SRS §3.5 (V-12): quantity precision must not exceed the unit's allowed decimals — rejected, not
// silently truncated. Mirrors InventoryReceivingService's existing whole-number rule for
// allows_decimal = false units.

it('rejects a fractional quantity on a unit that does not allow decimals, without truncating it', function (): void {
    $destination = Warehouse::factory()->create();
    $unit = Unit::factory()->create(['allows_decimal' => false]);
    $variant = ProductVariant::factory()->create(['unit_id' => $unit->getKey()]);
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $line = $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '2.500', 'unit_id' => $unit->getKey()]);

    expect(fn (): InventoryOperation => guardsService()->markReady($operation))
        ->toThrow(DomainException::class, __('admin.inventory.operation.errors.invalid_quantity_precision'));

    expect((float) $line->refresh()->quantity)->toBe(2.5);
});

// V-06: an operation cannot leave Draft with zero lines.

it('blocks an operation with no lines from leaving Draft', function (): void {
    $operation = InventoryOperation::factory()->receipt()->create();

    expect(fn (): InventoryOperation => guardsService()->markReady($operation))
        ->toThrow(DomainException::class, __('admin.inventory.operation.errors.no_lines'));
});
