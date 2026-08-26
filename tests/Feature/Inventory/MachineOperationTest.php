<?php

declare(strict_types=1);

use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function machineOperationService(): InventoryOperationService
{
    return app(InventoryOperationService::class);
}

it('requires every machine line to name the device it moves', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
    ]);

    machineOperationService()->markReady($operation);
})->throws(DomainException::class);

it('refuses a machine line covering more than one device', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $device = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '2.000',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $device->getKey(),
    ]);

    machineOperationService()->markReady($operation);
})->throws(DomainException::class);

it('refuses a fractional machine quantity', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $device = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '0.500',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $device->getKey(),
    ]);

    machineOperationService()->markReady($operation);
})->throws(DomainException::class);

it('receives one device per line and never creates a lot', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $devices = SerializedInventoryUnit::factory()->count(2)->for($variant, 'productVariant')->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);

    foreach ($devices as $device) {
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '1.000',
            'unit_id' => $variant->unit_id,
            'serialized_inventory_unit_id' => $device->getKey(),
        ]);
    }

    $actor = User::factory()->create();

    machineOperationService()->markReady($operation);
    machineOperationService()->complete($operation->refresh(), $actor);

    $stock = InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $destination->getKey())
        ->sole();

    expect((float) $stock->on_hand_quantity)->toBe(2.0)
        ->and($operation->refresh()->lines()->whereNotNull('inventory_lot_id')->count())->toBe(0);

    foreach ($devices as $device) {
        $device->refresh();

        expect($device->status)->toBe(SerializedInventoryUnitStatus::Available)
            ->and($device->warehouse_id)->toBe($destination->getKey());
    }
});

it('refuses expiry information on a machine line', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $device = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $device->getKey(),
        'expires_at' => today()->addYear(),
    ]);

    machineOperationService()->markReady($operation);
})->throws(DomainException::class);

it('refuses a serial on a line whose product is not a machine', function (): void {
    $destination = Warehouse::factory()->create();
    $grain = ProductVariant::factory()->grain()->create();
    $machine = ProductVariant::factory()->machine()->create();
    $device = SerializedInventoryUnit::factory()->for($machine, 'productVariant')->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $grain->getKey(),
        'quantity' => '3.000',
        'unit_id' => $grain->unit_id,
        'serialized_inventory_unit_id' => $device->getKey(),
    ]);

    machineOperationService()->markReady($operation);
})->throws(DomainException::class);

it('still rejects a device already committed to another live operation', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $device = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'status' => SerializedInventoryUnitStatus::Available,
    ]);

    $first = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $first->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $device->getKey(),
    ]);

    $second = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $second->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $device->getKey(),
    ]);

    machineOperationService()->markReady($second);
})->throws(DomainException::class);

it('finalizes a device as Delivered when its delivery operation completes', function (): void {
    $source = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    InventoryStock::factory()->for($variant)->for($source)->create(['on_hand_quantity' => '1.000', 'reserved_quantity' => '0.000', 'available_quantity' => '1.000']);
    $device = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($source)->create(['status' => SerializedInventoryUnitStatus::Available]);
    $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $device->getKey(),
    ]);
    $actor = User::factory()->create();

    machineOperationService()->markReady($operation);
    machineOperationService()->complete($operation->refresh(), $actor);

    expect($device->refresh()->status)->toBe(SerializedInventoryUnitStatus::Delivered)
        ->and($device->warehouse_id)->toBe($source->getKey());
});

it('marks a device InTransit on dispatch and lands it Available at the destination on complete', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    InventoryStock::factory()->for($variant)->for($source)->create(['on_hand_quantity' => '1.000', 'reserved_quantity' => '0.000', 'available_quantity' => '1.000']);
    $device = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($source)->create(['status' => SerializedInventoryUnitStatus::Available]);
    $operation = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $device->getKey(),
    ]);
    $actor = User::factory()->create();

    machineOperationService()->markReady($operation);
    machineOperationService()->dispatch($operation->refresh(), $actor);

    expect($device->refresh()->status)->toBe(SerializedInventoryUnitStatus::InTransit)
        ->and($device->warehouse_id)->toBe($source->getKey());

    machineOperationService()->complete($operation->refresh(), $actor);

    expect($device->refresh()->status)->toBe(SerializedInventoryUnitStatus::Available)
        ->and($device->warehouse_id)->toBe($destination->getKey());
});

it('restores a device to Available when an InTransit internal transfer is canceled', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    InventoryStock::factory()->for($variant)->for($source)->create(['on_hand_quantity' => '1.000', 'reserved_quantity' => '0.000', 'available_quantity' => '1.000']);
    $device = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($source)->create(['status' => SerializedInventoryUnitStatus::Available]);
    $operation = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'serialized_inventory_unit_id' => $device->getKey(),
    ]);
    $actor = User::factory()->create();

    machineOperationService()->markReady($operation);
    machineOperationService()->dispatch($operation->refresh(), $actor);
    machineOperationService()->cancel($operation->refresh(), $actor, 'device stayed put');

    expect($device->refresh()->status)->toBe(SerializedInventoryUnitStatus::Available)
        ->and($device->warehouse_id)->toBe($source->getKey());
});
