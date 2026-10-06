<?php

declare(strict_types=1);

use App\Enums\SerializedCustodyType;
use App\Enums\WarrantyEntitlementState;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Services\Support\WarrantyEntitlementService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

it('allows an authorized warranty date correction and synchronizes the serialized unit snapshot with an audit trail', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => CustomerProfile::class,
        'custody_reference_id' => $customer->getKey(),
        'warranty_started_on' => today()->subMonth(),
        'warranty_expires_on' => today()->addMonths(11),
    ]);
    $entitlement = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'customer_id' => $customer->getKey(),
        'state' => WarrantyEntitlementState::Active,
        'starts_on' => today()->subMonth(),
        'expires_on' => today()->addMonths(11),
    ]);

    $correctedStart = today()->subWeeks(2);
    $correctedExpiry = today()->addYear();

    $corrected = app(WarrantyEntitlementService::class)->correctDates(
        $entitlement,
        $correctedStart,
        $correctedExpiry,
        $manager,
        'Corrected against the signed installation certificate.',
    );

    expect($corrected->starts_on?->toDateString())->toBe($correctedStart->toDateString())
        ->and($corrected->expires_on?->toDateString())->toBe($correctedExpiry->toDateString())
        ->and($unit->refresh()->warranty_started_on?->toDateString())->toBe($correctedStart->toDateString())
        ->and($unit->warranty_expires_on?->toDateString())->toBe($correctedExpiry->toDateString())
        ->and(Activity::query()
            ->where('subject_type', $entitlement->getMorphClass())
            ->where('subject_id', $entitlement->getKey())
            ->where('description', 'support.warranty_entitlement.dates_corrected')
            ->exists())->toBeTrue();
});

it('rejects warranty corrections without a reason or with an invalid date range', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => CustomerProfile::class,
        'custody_reference_id' => $customer->getKey(),
    ]);
    $entitlement = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'customer_id' => $customer->getKey(),
        'state' => WarrantyEntitlementState::Active,
    ]);

    expect(fn () => app(WarrantyEntitlementService::class)->correctDates(
        $entitlement,
        today(),
        today()->addYear(),
        $manager,
        '   ',
    ))->toThrow(DomainException::class, 'correction reason');

    expect(fn () => app(WarrantyEntitlementService::class)->correctDates(
        $entitlement,
        today(),
        today()->subDay(),
        $manager,
        'Incorrect date range test.',
    ))->toThrow(ValidationException::class);
});
