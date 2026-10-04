<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\InstallationCheckResult;
use App\Enums\MaintenanceKind;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyStartTrigger;
use App\Models\CustomerProfile;
use App\Models\EquipmentInstallation;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Services\Support\EquipmentInstallationService;

/** Shared builders for the equipment installation tests. */
final class InstallationFixtures
{
    public static function manager(): User
    {
        $user = User::factory()->admin()->create();
        $user->assignRole('Support Manager');

        return $user;
    }

    public static function agent(): User
    {
        $user = User::factory()->admin()->create();
        $user->assignRole('Support Agent');

        return $user;
    }

    public static function reviewer(): User
    {
        $user = User::factory()->admin()->create();
        $user->assignRole('Reviewer');

        return $user;
    }

    /** @return array{0: CustomerProfile, 1: SerializedInventoryUnit, 2: MaintenanceRecord} */
    public static function scenario(): array
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

    /** A delivery of the unit to the customer with a shipment in the requested state. */
    public static function shipment(CustomerProfile $customer, SerializedInventoryUnit $unit, bool $arrived = true): Shipment
    {
        $delivery = InventoryOperation::factory()->delivery()->done()->create(['customer_id' => $customer->getKey()]);

        InventoryMovement::factory()->for($unit->productVariant, 'productVariant')->create([
            'source_type' => 'inventory_operation',
            'source_id' => $delivery->getKey(),
            'serialized_inventory_unit_id' => $unit->getKey(),
            'movement_type' => MovementType::Sale,
            'quantity' => -1,
        ]);

        $factory = Shipment::factory()->forCustomer($customer);

        return ($arrived ? $factory->arrived() : $factory)->create(['inventory_operation_id' => $delivery->getKey()]);
    }

    public static function pendingEntitlement(CustomerProfile $customer, SerializedInventoryUnit $unit, WarrantyStartTrigger $trigger): WarrantyEntitlement
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

    public static function passAllChecks(EquipmentInstallation $installation, User $actor): void
    {
        foreach ($installation->checks as $check) {
            app(EquipmentInstallationService::class)->recordCheck($installation, $check->check_key, InstallationCheckResult::Passed, $actor);
        }
    }

    /** Drives an installation through the requested number of milestones (1=created .. 5=accepted). */
    public static function installation(MaintenanceRecord $record, User $actor, int $stage = 1): EquipmentInstallation
    {
        $service = app(EquipmentInstallationService::class);
        $installation = $service->createForDeliveredEquipment($record, $actor);

        if ($stage >= 2) {
            $service->completeInstallation($installation, $actor);
        }

        if ($stage >= 3) {
            self::passAllChecks($installation->fresh(), $actor);
            $service->completeCommissioning($installation, $actor);
        }

        if ($stage >= 4) {
            $service->acceptByCustomer($installation, 'Dr. Salem', $actor);
        }

        return $installation->fresh();
    }
}
