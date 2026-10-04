<?php

declare(strict_types=1);

use App\Enums\CommissioningStatus;
use App\Enums\CustomerAcceptanceStatus;
use App\Enums\InstallationCheckResult;
use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyStartTrigger;
use App\Models\CustomerProfile;
use App\Models\EquipmentInstallation;
use App\Models\EquipmentInstallationCheck;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Services\Support\EquipmentInstallationService;
use App\Services\Support\WarrantyActivationService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InstallationFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

function installationManager(): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole('Support Manager');

    return $user;
}

/** @return array{0: CustomerProfile, 1: SerializedInventoryUnit, 2: MaintenanceRecord} */
function installationScenario(): array
{
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
    ]);
    $record = MaintenanceRecord::factory()->create([
        'customer_id' => $customer->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'maintenance_kind' => MaintenanceKind::Installation,
    ]);

    return [$customer, $unit, $record];
}

function pendingEntitlement(CustomerProfile $customer, SerializedInventoryUnit $unit, WarrantyStartTrigger $trigger): WarrantyEntitlement
{
    return WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'customer_id' => $customer->getKey(),
        'state' => WarrantyEntitlementState::PendingActivation,
        'start_trigger' => $trigger,
        'duration_value' => 12,
        'duration_unit' => WarrantyDurationUnit::Months,
        'starts_on' => null,
        'expires_on' => null,
    ]);
}

function passAllChecks(EquipmentInstallation $installation, User $actor): void
{
    foreach ($installation->checks as $check) {
        app(EquipmentInstallationService::class)->recordCheck($installation, $check->check_key, InstallationCheckResult::Passed, $actor);
    }
}

it('creates an installation with the default checklist for delivered customer equipment', function (): void {
    [, , $record] = installationScenario();

    $installation = app(EquipmentInstallationService::class)->createForDeliveredEquipment($record, installationManager(), [
        'installation_location' => 'Lab 2',
    ]);

    expect($installation->commissioning_status)->toBe(CommissioningStatus::Pending)
        ->and($installation->customer_acceptance_status)->toBe(CustomerAcceptanceStatus::Pending)
        ->and($installation->installation_location)->toBe('Lab 2')
        ->and($installation->checks)->toHaveCount(count(EquipmentInstallationService::DEFAULT_CHECKLIST));
});

it('rejects installation for non-installation requests, other customers and duplicates', function (): void {
    [, $unit, $record] = installationScenario();
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);

    $record->update(['maintenance_kind' => MaintenanceKind::Corrective]);
    expect(fn () => $service->createForDeliveredEquipment($record, $actor))->toThrow(ValidationException::class);
    $record->update(['maintenance_kind' => MaintenanceKind::Installation]);

    $unit->update(['custody_reference_id' => CustomerProfile::factory()->create()->getKey()]);
    expect(fn () => $service->createForDeliveredEquipment($record, $actor))->toThrow(ValidationException::class);
    $unit->update(['custody_reference_id' => $record->customer_id]);

    $service->createForDeliveredEquipment($record, $actor);
    expect(fn () => $service->createForDeliveredEquipment($record, $actor))->toThrow(ValidationException::class);

    $second = MaintenanceRecord::factory()->create([
        'customer_id' => $record->customer_id,
        'serialized_inventory_unit_id' => $unit->getKey(),
        'maintenance_kind' => MaintenanceKind::Installation,
    ]);
    expect(fn () => $service->createForDeliveredEquipment($second, $actor))->toThrow(ValidationException::class);
});

it('rejects a finalised request, a request without equipment and an unconfirmed shipment', function (): void {
    [$customer, , $record] = installationScenario();
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);
    $shipment = Shipment::factory()->forCustomer($customer)->create();

    expect(fn () => $service->createForDeliveredEquipment($record, $actor, ['shipment_id' => $shipment->getKey()]))
        ->toThrow(ValidationException::class);

    $record->update(['status' => MaintenanceStatus::Closed]);
    expect(fn () => $service->createForDeliveredEquipment($record, $actor))->toThrow(ValidationException::class);

    $record->update(['status' => MaintenanceStatus::Open, 'serialized_inventory_unit_id' => null]);
    expect(fn () => $service->createForDeliveredEquipment($record, $actor))->toThrow(ValidationException::class);
});

it('requires installation before commissioning and passing checks before commissioning passes', function (): void {
    [, , $record] = installationScenario();
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);
    $installation = $service->createForDeliveredEquipment($record, $actor);

    expect(fn () => $service->completeCommissioning($installation, $actor))->toThrow(ValidationException::class);

    $service->completeInstallation($installation, $actor);

    expect(fn () => $service->completeCommissioning($installation, $actor))->toThrow(ValidationException::class)
        ->and(fn () => $service->completeInstallation($installation, $actor))->toThrow(ValidationException::class);

    passAllChecks($installation->fresh(), $actor);
    $service->completeCommissioning($installation, $actor);

    expect($installation->fresh()->commissioning_status)->toBe(CommissioningStatus::Passed)
        ->and($installation->fresh()->commissioned_at)->not->toBeNull()
        ->and(fn () => $service->completeCommissioning($installation, $actor))->toThrow(ValidationException::class);
});

it('allows not-applicable checks and rejects an unknown check key', function (): void {
    [, , $record] = installationScenario();
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);
    $installation = $service->createForDeliveredEquipment($record, $actor);
    $service->completeInstallation($installation, $actor);

    foreach ($installation->checks as $check) {
        $service->recordCheck($installation, $check->check_key, InstallationCheckResult::NotApplicable, $actor);
    }

    expect(fn () => $service->recordCheck($installation, 'nope', InstallationCheckResult::Passed, $actor))
        ->toThrow(ValidationException::class);

    $service->completeCommissioning($installation, $actor);

    expect($installation->fresh()->commissioning_status)->toBe(CommissioningStatus::Passed);
});

it('blocks customer acceptance until commissioning succeeds and records acceptance once', function (): void {
    [, , $record] = installationScenario();
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);
    $installation = $service->createForDeliveredEquipment($record, $actor);
    $service->completeInstallation($installation, $actor);

    expect(fn () => $service->acceptByCustomer($installation, 'Dr. Salem', $actor))->toThrow(ValidationException::class);

    $service->failCommissioning($installation, $actor, 'Temperature drift');

    expect($installation->fresh()->commissioning_status)->toBe(CommissioningStatus::Failed)
        ->and(fn () => $service->acceptByCustomer($installation, 'Dr. Salem', $actor))->toThrow(ValidationException::class);

    passAllChecks($installation->fresh(), $actor);
    $service->completeCommissioning($installation, $actor);

    expect(fn () => $service->acceptByCustomer($installation, ' ', $actor))->toThrow(ValidationException::class);

    $service->acceptByCustomer($installation, 'Dr. Salem', $actor);

    expect($installation->fresh()->customer_acceptance_status)->toBe(CustomerAcceptanceStatus::Accepted)
        ->and($installation->fresh()->customer_signatory_name)->toBe('Dr. Salem')
        ->and(fn () => $service->rejectByCustomer($installation, 'Changed mind', $actor))->toThrow(ValidationException::class);
});

it('records a customer rejection with a mandatory reason', function (): void {
    [, , $record] = installationScenario();
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);
    $installation = $service->createForDeliveredEquipment($record, $actor);
    $service->completeInstallation($installation, $actor);
    passAllChecks($installation->fresh(), $actor);
    $service->completeCommissioning($installation, $actor);

    expect(fn () => $service->rejectByCustomer($installation, '', $actor))->toThrow(ValidationException::class);

    $service->rejectByCustomer($installation, 'Noise level', $actor);

    expect($installation->fresh()->customer_acceptance_status)->toBe(CustomerAcceptanceStatus::Rejected);
});

it('requires a reason to fail commissioning and an installed unit', function (): void {
    [, , $record] = installationScenario();
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);
    $installation = $service->createForDeliveredEquipment($record, $actor);

    expect(fn () => $service->failCommissioning($installation, $actor, ' '))->toThrow(ValidationException::class)
        ->and(fn () => $service->failCommissioning($installation, $actor, 'x'))->toThrow(ValidationException::class);

    $service->completeInstallation($installation, $actor);
    passAllChecks($installation->fresh(), $actor);
    $service->completeCommissioning($installation, $actor);

    expect(fn () => $service->failCommissioning($installation, $actor, 'late'))->toThrow(ValidationException::class);
});

it('freezes the installation once the maintenance request is closed', function (): void {
    [, , $record] = installationScenario();
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);
    $installation = $service->createForDeliveredEquipment($record, $actor);
    $record->update(['status' => MaintenanceStatus::Closed]);

    expect(fn () => $service->completeInstallation($installation, $actor))->toThrow(ValidationException::class);
});

it('rejects completion when the equipment no longer matches the request or customer', function (): void {
    [, $unit, $record] = installationScenario();
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);
    $installation = $service->createForDeliveredEquipment($record, $actor);

    $unit->update(['custody_reference_id' => CustomerProfile::factory()->create()->getKey()]);
    expect(fn () => $service->completeInstallation($installation, $actor))->toThrow(ValidationException::class);

    $unit->update(['custody_reference_id' => $record->customer_id]);
    $record->update(['serialized_inventory_unit_id' => SerializedInventoryUnit::factory()->create()->getKey()]);
    expect(fn () => $service->completeInstallation($installation, $actor))->toThrow(ValidationException::class);
});

it('blocks every installation ability when the feature flag is disabled but keeps historical rows', function (): void {
    [, , $record] = installationScenario();
    $manager = installationManager();
    $installation = app(EquipmentInstallationService::class)->createForDeliveredEquipment($record, $manager);

    config(['support.equipment_installation_enabled' => false]);

    expect(fn () => app(EquipmentInstallationService::class)->completeInstallation($installation, $manager))
        ->toThrow(AuthorizationException::class)
        ->and($manager->can('viewAny', EquipmentInstallation::class))->toBeFalse()
        ->and($manager->can('create', EquipmentInstallation::class))->toBeFalse()
        ->and(EquipmentInstallation::query()->whereKey($installation->getKey())->exists())->toBeTrue();
});

it('lists only arrived, confirmed shipments that delivered this unit to this customer', function (): void {
    [$customer, $unit, $record] = installationScenario();
    $good = InstallationFixtures::shipment($customer, $unit);
    InstallationFixtures::shipment($customer, $unit, arrived: false);
    InstallationFixtures::shipment(CustomerProfile::factory()->create(), $unit);
    InstallationFixtures::shipment($customer, SerializedInventoryUnit::factory()->create());

    $eligible = app(EquipmentInstallationService::class)->eligibleShipments($record);

    expect($eligible->pluck('id')->all())->toBe([$good->id]);

    $record->update(['serialized_inventory_unit_id' => null]);

    expect(app(EquipmentInstallationService::class)->eligibleShipments($record->fresh()))->toBeEmpty();
});

it('creates an installation from the selected eligible shipment and refuses ineligible ones', function (): void {
    [$customer, $unit, $record] = installationScenario();
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);

    $planned = InstallationFixtures::shipment($customer, $unit, arrived: false);
    $foreign = InstallationFixtures::shipment(CustomerProfile::factory()->create(), $unit);
    $otherUnit = InstallationFixtures::shipment($customer, SerializedInventoryUnit::factory()->create());
    $good = InstallationFixtures::shipment($customer, $unit);

    foreach ([$planned, $foreign, $otherUnit] as $bad) {
        expect(fn () => $service->createForDeliveredEquipment($record, $actor, ['shipment_id' => $bad->id]))
            ->toThrow(ValidationException::class);
    }

    $installation = $service->createForDeliveredEquipment($record, $actor, ['shipment_id' => $good->id]);

    expect($installation->shipment_id)->toBe($good->id)
        ->and($installation->shipment?->is($good))->toBeTrue();
});

it('keeps the commissioning failure and customer rejection reasons visible after the state changes', function (): void {
    [, , $record] = installationScenario();
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);
    $installation = $service->createForDeliveredEquipment($record, $actor);
    $service->completeInstallation($installation, $actor);

    $service->failCommissioning($installation, $actor, 'Temperature drift');

    expect($installation->fresh()->commissioning_failure_reason)->toBe('Temperature drift');

    InstallationFixtures::passAllChecks($installation->fresh(), $actor);
    $service->completeCommissioning($installation, $actor);
    expect($installation->fresh()->commissioning_failure_reason)->toBeNull();

    $service->rejectByCustomer($installation, 'Noise level', $actor);
    $fresh = $installation->fresh();

    expect($fresh->customer_rejection_reason)->toBe('Noise level')
        ->and($fresh->customer_rejected_at)->not->toBeNull()
        ->and(Activity::query()->where('description', 'support.installation.rejected')->count())->toBe(1);
});

it('enforces installation permissions per role', function (): void {
    [, , $record] = installationScenario();
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $unauthorised = User::factory()->customer()->create();
    $service = app(EquipmentInstallationService::class);

    expect(fn () => $service->createForDeliveredEquipment($record, $agent))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->createForDeliveredEquipment($record, $unauthorised))->toThrow(AuthorizationException::class);

    $installation = $service->createForDeliveredEquipment($record, installationManager());

    expect(fn () => $service->completeInstallation($installation, $reviewer))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->recordCheck($installation, 'functional_test', InstallationCheckResult::Passed, $reviewer))->toThrow(AuthorizationException::class);

    $service->recordCheck($installation, 'functional_test', InstallationCheckResult::Passed, $agent);
    $service->completeInstallation($installation, $agent);

    expect($installation->fresh()->isInstalled())->toBeTrue()
        ->and($agent->can('viewAny', EquipmentInstallation::class))->toBeTrue()
        ->and($agent->can('view', $installation))->toBeTrue();
});

it('keeps an installation-trigger warranty pending after delivery and activates it on installation', function (): void {
    [$customer, $unit, $record] = installationScenario();
    $entitlement = pendingEntitlement($customer, $unit, WarrantyStartTrigger::Installation);
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);
    $installation = $service->createForDeliveredEquipment($record, $actor);

    expect($entitlement->fresh()->state)->toBe(WarrantyEntitlementState::PendingActivation);

    $service->completeInstallation($installation, $actor, now()->subDays(2));

    $entitlement->refresh();
    $unit->refresh();

    expect($entitlement->state)->toBe(WarrantyEntitlementState::Active)
        ->and($entitlement->starts_on?->toDateString())->toBe(today()->subDays(2)->toDateString())
        ->and($entitlement->expires_on?->toDateString())->toBe(today()->subDays(2)->addMonthsNoOverflow(12)->toDateString())
        ->and($unit->warranty_started_on?->toDateString())->toBe($entitlement->starts_on?->toDateString());
});

it('activates a commissioning-trigger warranty only after successful commissioning', function (): void {
    [$customer, $unit, $record] = installationScenario();
    $entitlement = pendingEntitlement($customer, $unit, WarrantyStartTrigger::Commissioning);
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);
    $installation = $service->createForDeliveredEquipment($record, $actor);

    $service->completeInstallation($installation, $actor);
    expect($entitlement->fresh()->state)->toBe(WarrantyEntitlementState::PendingActivation);

    $service->failCommissioning($installation, $actor, 'Calibration out of range');

    expect($entitlement->fresh()->state)->toBe(WarrantyEntitlementState::PendingActivation)
        ->and(app(WarrantyActivationService::class)->activateForCommissioning($installation->fresh()))->toBe(0);

    passAllChecks($installation->fresh(), $actor);
    $service->completeCommissioning($installation, $actor);

    expect($entitlement->fresh()->state)->toBe(WarrantyEntitlementState::Active)
        ->and($entitlement->fresh()->starts_on?->toDateString())->toBe(today()->toDateString());
});

it('leaves delivery-trigger entitlements alone and activation is idempotent', function (): void {
    [$customer, $unit, $record] = installationScenario();
    $delivery = pendingEntitlement($customer, $unit, WarrantyStartTrigger::ConfirmedDelivery);
    $installTrigger = pendingEntitlement($customer, $unit, WarrantyStartTrigger::Installation);
    $actor = installationManager();
    $service = app(EquipmentInstallationService::class);
    $installation = $service->createForDeliveredEquipment($record, $actor);
    $service->completeInstallation($installation, $actor);

    expect($delivery->fresh()->state)->toBe(WarrantyEntitlementState::PendingActivation)
        ->and($installTrigger->fresh()->state)->toBe(WarrantyEntitlementState::Active)
        ->and(app(WarrantyActivationService::class)->activateForInstallation($installation->fresh()))->toBe(0);
});

it('does not activate without an installed date and rejects mismatched equipment', function (): void {
    [$customer, $unit, $record] = installationScenario();
    pendingEntitlement($customer, $unit, WarrantyStartTrigger::Installation);
    $installation = EquipmentInstallation::factory()->create([
        'maintenance_record_id' => $record->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);
    $activation = app(WarrantyActivationService::class);

    expect($activation->activateForInstallation($installation))->toBe(0);

    $installation->update(['installed_at' => now()]);
    $record->update(['serialized_inventory_unit_id' => SerializedInventoryUnit::factory()->create()->getKey()]);

    expect(fn () => $activation->activateForInstallation($installation->fresh()))->toThrow(ValidationException::class);
});

it('registers the installation media collections and resolves relations', function (): void {
    $installation = EquipmentInstallation::factory()->commissioned()->create();

    expect($installation->getRegisteredMediaCollections()->pluck('name')->all())
        ->toBe([EquipmentInstallation::MEDIA_PHOTOS, EquipmentInstallation::MEDIA_COMMISSIONING, EquipmentInstallation::MEDIA_ACCEPTANCE])
        ->and($installation->maintenanceRecord)->not->toBeNull()
        ->and($installation->serializedInventoryUnit)->not->toBeNull()
        ->and($installation->shipment)->toBeNull()
        ->and($installation->installedBy)->toBeNull()
        ->and($installation->commissionedBy)->toBeNull();

    $check = EquipmentInstallationCheck::factory()->create(['equipment_installation_id' => $installation->getKey()]);

    expect($check->installation->is($installation))->toBeTrue()
        ->and($check->result)->toBe(InstallationCheckResult::Pending)
        ->and(InstallationCheckResult::NotApplicable->satisfiesCommissioning())->toBeTrue()
        ->and(InstallationCheckResult::Failed->satisfiesCommissioning())->toBeFalse()
        ->and(CommissioningStatus::Passed->label())->toBe('Passed');
});
