<?php

declare(strict_types=1);

use App\Enums\SerializedCustodyType;
use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyStartTrigger;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Services\Support\WarrantyEntitlementService;
use App\Services\Support\WarrantyResolver;
use Carbon\Carbon;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('rejects replacement equipment that has not been persisted', function (): void {
    $manager = coverage65Manager();
    $customer = CustomerProfile::factory()->create();
    $original = new WarrantyEntitlement;
    $original->forceFill([
        'state' => WarrantyEntitlementState::Active,
        'expires_on' => today()->addMonth(),
        'customer_id' => $customer->id,
        'serialized_inventory_unit_id' => 22,
    ]);
    $replacement = SerializedInventoryUnit::factory()->make([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->id,
    ]);

    expect(fn () => app(WarrantyEntitlementService::class)->applyReplacement($original, $replacement, $manager, 'Replace faulty unit'))
        ->toThrow(ValidationException::class, 'The replacement serial has an invalid identifier.');
});

function coverage65Manager(): User
{
    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

function coverage65PendingEntitlement(
    CustomerProfile $customer,
    SerializedInventoryUnit $unit,
    WarrantyDurationUnit $unitType = WarrantyDurationUnit::Months,
    int $duration = 12,
): WarrantyEntitlement {
    return WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'customer_id' => $customer->id,
        'state' => WarrantyEntitlementState::PendingActivation,
        'duration_value' => $duration,
        'duration_unit' => $unitType,
        'start_trigger' => WarrantyStartTrigger::Installation,
        'starts_on' => null,
        'expires_on' => null,
    ]);
}

it('manually activates a pending warranty entitlement and synchronizes the serialized-unit snapshot', function (): void {
    $manager = coverage65Manager();
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->id,
        'warranty_started_on' => null,
        'warranty_expires_on' => null,
    ]);
    $entitlement = coverage65PendingEntitlement($customer, $unit);

    $activated = app(WarrantyEntitlementService::class)->activate(
        $entitlement,
        Carbon::parse('2026-10-05'),
        $manager,
        'Installation completed.',
    );

    expect($activated->state)->toBe(WarrantyEntitlementState::Active)
        ->and($activated->starts_on?->toDateString())->toBe('2026-10-05')
        ->and($activated->expires_on?->toDateString())->toBe('2027-10-05')
        ->and($unit->refresh()->warranty_started_on?->toDateString())->toBe('2026-10-05')
        ->and($unit->warranty_expires_on?->toDateString())->toBe('2027-10-05');
});

it('rejects manual warranty activation with a processed entitlement or blank reason', function (): void {
    $manager = coverage65Manager();
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->id,
    ]);
    $service = app(WarrantyEntitlementService::class);

    $active = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'customer_id' => $customer->id,
        'state' => WarrantyEntitlementState::Active,
    ]);

    expect(fn () => $service->activate($active, today(), $manager, 'Already active'))
        ->toThrow(DomainException::class, 'Only a pending warranty entitlement');

    $pending = coverage65PendingEntitlement($customer, $unit);

    expect(fn () => $service->activate($pending, today(), $manager, '   '))
        ->toThrow(DomainException::class, 'activation reason is required');
});

it('covers warranty duration expiry calculations for days months and years', function (): void {
    $service = app(WarrantyEntitlementService::class);
    $expiry = new ReflectionMethod(WarrantyEntitlementService::class, 'expiry');
    $start = Carbon::parse('2024-02-29');

    expect($expiry->invoke($service, $start, 10, WarrantyDurationUnit::Days)->toDateString())
        ->toBe('2024-03-10')
        ->and($expiry->invoke($service, $start, 1, WarrantyDurationUnit::Months)->toDateString())
        ->toBe('2024-03-29')
        ->and($expiry->invoke($service, $start, 1, WarrantyDurationUnit::Years)->toDateString())
        ->toBe('2025-02-28');
});

it('resolves active entitlement coverage from the immutable entitlement snapshot', function (): void {
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->id,
    ]);
    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'customer_id' => $customer->id,
        'state' => WarrantyEntitlementState::Active,
        'starts_on' => today()->subMonth(),
        'expires_on' => today()->addMonth(),
    ]);

    $coverage = app(WarrantyResolver::class)->resolveForSerializedUnit($unit, $customer);

    expect($coverage->status)->toBe(WarrantyStatus::Covered)
        ->and($coverage->reason)->toContain('active customer warranty entitlement');
});
