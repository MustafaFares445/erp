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
