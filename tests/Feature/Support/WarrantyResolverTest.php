<?php

declare(strict_types=1);

use App\Enums\SerializedCustodyType;
use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Services\Support\WarrantyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports a customer-owned serialized unit as covered inside its activated warranty window', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'warranty_duration_value' => 12,
        'warranty_duration_unit' => WarrantyDurationUnit::Months,
    ]);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->id,
        'warranty_started_on' => today()->subMonth(),
        'warranty_expires_on' => today()->addMonths(11),
    ]);

    $coverage = app(WarrantyResolver::class)->resolveForSerializedUnit($unit, $customer);

    expect($coverage->status)->toBe(WarrantyStatus::Covered)
        ->and($coverage->expiresOn?->toDateString())->toBe(today()->addMonths(11)->toDateString());
});

it('reports an activated warranty as expired after its expiry date', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'warranty_duration_value' => 1,
        'warranty_duration_unit' => WarrantyDurationUnit::Years,
    ]);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->id,
        'warranty_started_on' => today()->subYears(2),
        'warranty_expires_on' => today()->subYear(),
    ]);

    expect(app(WarrantyResolver::class)->resolveForSerializedUnit($unit, $customer)->status)
        ->toBe(WarrantyStatus::Expired);
});

it('reports known sold equipment with no configured warranty as not covered', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'warranty_duration_value' => null,
        'warranty_duration_unit' => null,
    ]);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->id,
        'warranty_started_on' => null,
        'warranty_expires_on' => null,
    ]);

    expect(app(WarrantyResolver::class)->resolveForSerializedUnit($unit, $customer)->status)
        ->toBe(WarrantyStatus::NotCovered);
});

it('keeps warranty unknown when warranty terms exist but no reliable confirmed-delivery snapshot exists', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'warranty_duration_value' => 12,
        'warranty_duration_unit' => WarrantyDurationUnit::Months,
    ]);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->id,
        'warranty_started_on' => null,
        'warranty_expires_on' => null,
    ]);

    expect(app(WarrantyResolver::class)->resolveForSerializedUnit($unit, $customer)->status)
        ->toBe(WarrantyStatus::Unknown);
});

it('does not treat a serial in another customer custody as covered', function (): void {
    $customer = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $other->id,
        'warranty_started_on' => today()->subMonth(),
        'warranty_expires_on' => today()->addYear(),
    ]);

    expect(app(WarrantyResolver::class)->resolveForSerializedUnit($unit, $customer)->status)
        ->toBe(WarrantyStatus::Unknown);
});

it('marks external equipment as not applicable to IERP sale warranty', function (): void {
    expect(app(WarrantyResolver::class)->externalEquipment()->status)
        ->toBe(WarrantyStatus::NotApplicable);
});
