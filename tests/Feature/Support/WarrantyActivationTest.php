<?php

declare(strict_types=1);

use App\Enums\MovementType;
use App\Enums\WarrantyDurationUnit;
use App\Models\CustomerProfile;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Services\Shipments\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('activates a serialized customer warranty from the confirmed shipment date using delivery provenance', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'warranty_duration_value' => 12,
        'warranty_duration_unit' => WarrantyDurationUnit::Months,
    ]);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create();
    $delivery = InventoryOperation::factory()->delivery()->for($customer, 'customer')->done()->create();

    InventoryMovement::factory()->for($variant, 'productVariant')->create([
        'source_type' => 'inventory_operation',
        'source_id' => $delivery->id,
        'serialized_inventory_unit_id' => $unit->id,
        'movement_type' => MovementType::Sale,
        'quantity' => -1,
    ]);

    $shipment = Shipment::factory()->forCustomer($customer)->create([
        'inventory_operation_id' => $delivery->id,
    ]);

    app(ShipmentService::class)->confirmBySystem($shipment);

    $unit->refresh();

    expect($unit->warranty_started_on?->toDateString())->toBe(today()->toDateString())
        ->and($unit->warranty_expires_on?->toDateString())->toBe(today()->addMonthsNoOverflow(12)->toDateString())
        ->and($unit->warranty_source_shipment_id)->toBe($shipment->id);
});

it('does not activate a warranty when the product variant has no customer warranty terms', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'warranty_duration_value' => null,
        'warranty_duration_unit' => null,
    ]);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create();
    $delivery = InventoryOperation::factory()->delivery()->for($customer, 'customer')->done()->create();

    InventoryMovement::factory()->for($variant, 'productVariant')->create([
        'source_type' => 'inventory_operation',
        'source_id' => $delivery->id,
        'serialized_inventory_unit_id' => $unit->id,
        'movement_type' => MovementType::Sale,
        'quantity' => -1,
    ]);

    $shipment = Shipment::factory()->forCustomer($customer)->create(['inventory_operation_id' => $delivery->id]);
    app(ShipmentService::class)->confirmBySystem($shipment);

    expect($unit->refresh()->warranty_started_on)->toBeNull()
        ->and($unit->warranty_expires_on)->toBeNull();
});

it('keeps an already activated warranty immutable when shipment confirmation is repeated later', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'warranty_duration_value' => 1,
        'warranty_duration_unit' => WarrantyDurationUnit::Years,
    ]);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create();
    $delivery = InventoryOperation::factory()->delivery()->for($customer, 'customer')->done()->create();

    InventoryMovement::factory()->for($variant, 'productVariant')->create([
        'source_type' => 'inventory_operation',
        'source_id' => $delivery->id,
        'serialized_inventory_unit_id' => $unit->id,
        'movement_type' => MovementType::Sale,
        'quantity' => -1,
    ]);

    $shipment = Shipment::factory()->forCustomer($customer)->create(['inventory_operation_id' => $delivery->id]);
    $service = app(ShipmentService::class);
    $service->confirmBySystem($shipment);

    $started = $unit->refresh()->warranty_started_on?->toDateString();
    $expires = $unit->warranty_expires_on?->toDateString();

    $this->travel(30)->days();
    $service->confirmBySystem($shipment->refresh());

    expect($unit->refresh()->warranty_started_on?->toDateString())->toBe($started)
        ->and($unit->warranty_expires_on?->toDateString())->toBe($expires);
});
