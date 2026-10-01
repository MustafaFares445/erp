<?php

declare(strict_types=1);

use App\Enums\MovementType;
use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyStartTrigger;
use App\Models\CustomerProfile;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\WarrantyEntitlement;
use App\Models\WarrantyPolicy;
use App\Services\Shipments\ShipmentService;
use App\Services\Support\WarrantyActivationService;
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

it('snapshots a reusable warranty policy into an immutable entitlement at confirmed delivery', function (): void {
    $customer = CustomerProfile::factory()->create();
    $policy = WarrantyPolicy::factory()->create([
        'duration_value' => 24,
        'duration_unit' => WarrantyDurationUnit::Months,
        'start_trigger' => WarrantyStartTrigger::ConfirmedDelivery,
        'covers_parts' => true,
        'covers_labour' => true,
        'covers_travel' => true,
        'covers_consumables' => false,
    ]);
    $variant = ProductVariant::factory()->create([
        'warranty_policy_id' => $policy->getKey(),
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

    $entitlement = WarrantyEntitlement::query()->where('serialized_inventory_unit_id', $unit->id)->sole();

    expect($entitlement->warranty_policy_id)->toBe($policy->getKey())
        ->and($entitlement->policy_name)->toBe($policy->name)
        ->and($entitlement->duration_value)->toBe(24)
        ->and($entitlement->state)->toBe(WarrantyEntitlementState::Active)
        ->and($entitlement->covers_parts)->toBeTrue()
        ->and($entitlement->covers_labour)->toBeTrue()
        ->and($entitlement->covers_travel)->toBeTrue()
        ->and($entitlement->expires_on?->toDateString())->toBe(today()->addMonthsNoOverflow(24)->toDateString());

    $policy->update([
        'duration_value' => 6,
        'covers_travel' => false,
    ]);

    expect($entitlement->refresh()->duration_value)->toBe(24)
        ->and($entitlement->covers_travel)->toBeTrue();
});

it('creates a pending entitlement when the policy begins from installation instead of delivery', function (): void {
    $customer = CustomerProfile::factory()->create();
    $policy = WarrantyPolicy::factory()->create([
        'duration_value' => 12,
        'duration_unit' => WarrantyDurationUnit::Months,
        'start_trigger' => WarrantyStartTrigger::Installation,
    ]);
    $variant = ProductVariant::factory()->create([
        'warranty_policy_id' => $policy->getKey(),
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

    $entitlement = WarrantyEntitlement::query()->where('serialized_inventory_unit_id', $unit->id)->sole();

    expect($entitlement->state)->toBe(WarrantyEntitlementState::PendingActivation)
        ->and($entitlement->starts_on)->toBeNull()
        ->and($entitlement->expires_on)->toBeNull()
        ->and($unit->refresh()->warranty_started_on)->toBeNull()
        ->and($unit->warranty_expires_on)->toBeNull();
});

it('ends the previous customer entitlement and starts a new one when the same serial is delivered to another customer', function (): void {
    $firstCustomer = CustomerProfile::factory()->create();
    $secondCustomer = CustomerProfile::factory()->create();
    $policy = WarrantyPolicy::factory()->create([
        'duration_value' => 12,
        'duration_unit' => WarrantyDurationUnit::Months,
        'start_trigger' => WarrantyStartTrigger::ConfirmedDelivery,
    ]);
    $variant = ProductVariant::factory()->create([
        'warranty_policy_id' => $policy->getKey(),
        'warranty_duration_value' => null,
        'warranty_duration_unit' => null,
    ]);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create();
    $firstEntitlement = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'customer_id' => $firstCustomer->id,
        'warranty_policy_id' => $policy->id,
        'state' => WarrantyEntitlementState::Active,
        'starts_on' => today()->subMonth(),
        'expires_on' => today()->addMonths(11),
    ]);
    $unit->forceFill([
        'warranty_started_on' => $firstEntitlement->starts_on,
        'warranty_expires_on' => $firstEntitlement->expires_on,
    ])->save();

    $this->travel(30)->days();

    $secondDelivery = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $secondCustomer->id,
    ]);
    InventoryMovement::factory()->for($variant, 'productVariant')->create([
        'source_type' => 'inventory_operation',
        'source_id' => $secondDelivery->id,
        'serialized_inventory_unit_id' => $unit->id,
        'movement_type' => MovementType::Sale,
        'quantity' => -1,
    ]);
    $secondShipment = Shipment::factory()->arrived()->forCustomer($secondCustomer)->create([
        'inventory_operation_id' => $secondDelivery->id,
    ]);
    $activated = app(WarrantyActivationService::class)->activateForShipment($secondShipment);

    expect($activated)->toBe(1);
    $secondEntitlement = WarrantyEntitlement::query()->where('customer_id', $secondCustomer->id)->sole();

    expect($firstEntitlement->refresh()->state)->toBe(WarrantyEntitlementState::Ended)
        ->and($firstEntitlement->ended_at)->not->toBeNull()
        ->and($secondEntitlement->state)->toBe(WarrantyEntitlementState::Active)
        ->and($secondEntitlement->starts_on?->toDateString())->toBe(today()->toDateString())
        ->and($unit->refresh()->warranty_source_shipment_id)->toBe($secondShipment->id);
});
