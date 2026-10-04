<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\CommissioningStatus;
use App\Enums\CustomerAcceptanceStatus;
use App\Enums\InstallationCheckResult;
use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Enums\NotificationEventKey;
use App\Enums\SerializedCustodyType;
use App\Enums\ShipmentStatus;
use App\Events\EquipmentInstallationMilestone;
use App\Models\EquipmentInstallation;
use App\Models\EquipmentInstallationCheck;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates installation, commissioning and customer acceptance of a
 * delivered serialized unit. Support owns only the service facts here; it
 * never touches custody, stock or accounting. Warranty entitlements that start
 * on installation/commissioning are activated through
 * {@see WarrantyActivationService}.
 */
final readonly class EquipmentInstallationService
{
    /** @var list<array{key:string,label:string,unit?:string}> */
    public const array DEFAULT_CHECKLIST = [
        ['key' => 'unpack_inspection', 'label' => 'Unpacking and visual inspection'],
        ['key' => 'placement_levelling', 'label' => 'Placement and levelling'],
        ['key' => 'utilities_connection', 'label' => 'Power, air and water connections'],
        ['key' => 'functional_test', 'label' => 'Functional test run'],
        ['key' => 'safety_check', 'label' => 'Safety check'],
        ['key' => 'customer_training', 'label' => 'Operator training'],
    ];

    public function __construct(private WarrantyActivationService $warrantyActivation) {}

    /** @param array{shipment_id?:int|null,installation_location?:string|null,notes?:string|null} $data */
    public function createForDeliveredEquipment(MaintenanceRecord $record, User $actor, array $data = []): EquipmentInstallation
    {
        Gate::forUser($actor)->authorize('create', EquipmentInstallation::class);

        return DB::transaction(function () use ($record, $actor, $data): EquipmentInstallation {
            $locked = MaintenanceRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->maintenance_kind !== MaintenanceKind::Installation) {
                throw ValidationException::withMessages(['maintenance_record_id' => 'Only an installation maintenance request can carry an installation.']);
            }

            if ($locked->isFinalised()) {
                throw ValidationException::withMessages(['maintenance_record_id' => 'A closed or cancelled maintenance request cannot start an installation.']);
            }

            if ($locked->serialized_inventory_unit_id === null) {
                throw ValidationException::withMessages(['serialized_inventory_unit_id' => 'The maintenance request must reference serialized equipment.']);
            }

            $unit = SerializedInventoryUnit::query()->findOrFail($locked->serialized_inventory_unit_id);
            $this->assertUnitBelongsToCustomer($unit, (int) $locked->customer_id);

            $shipmentId = $data['shipment_id'] ?? null;
            if ($shipmentId !== null) {
                $this->assertShipmentDeliveredUnit(Shipment::query()->findOrFail($shipmentId), $unit, (int) $locked->customer_id);
            }

            if (EquipmentInstallation::query()->where('maintenance_record_id', $locked->getKey())->exists()
                || $this->hasConflictingInstallation($unit, $locked)) {
                throw ValidationException::withMessages(['serialized_inventory_unit_id' => 'An active installation already exists for this equipment.']);
            }

            $installation = EquipmentInstallation::query()->create([
                'maintenance_record_id' => $locked->getKey(),
                'serialized_inventory_unit_id' => $unit->getKey(),
                'shipment_id' => $shipmentId,
                'commissioning_status' => CommissioningStatus::Pending,
                'customer_acceptance_status' => CustomerAcceptanceStatus::Pending,
                'installation_location' => $data['installation_location'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach (self::DEFAULT_CHECKLIST as $index => $check) {
                $installation->checks()->create([
                    'check_key' => $check['key'],
                    'label' => $check['label'],
                    'result' => InstallationCheckResult::Pending,
                    'sort_order' => $index,
                ]);
            }

            $this->log($installation, $actor, 'support.installation.created');

            return $installation;
        });
    }

    /**
     * Arrived, confirmed shipments whose delivery to this request's customer
     * included the request's serialized unit: the only valid installation
     * shipments. The picker and the domain guard share this definition.
     *
     * @return Collection<int, Shipment>
     */
    public function eligibleShipments(MaintenanceRecord $record): Collection
    {
        if ($record->serialized_inventory_unit_id === null) {
            return new Collection;
        }

        return Shipment::query()
            ->where('status', ShipmentStatus::Arrived->value)
            ->whereNotNull('confirmed_at')
            ->whereHas('delivery', static fn (Builder $delivery): Builder => $delivery
                ->where('customer_id', $record->customer_id)
                ->whereHas('movements', static fn (Builder $movement): Builder => $movement
                    ->where('serialized_inventory_unit_id', $record->serialized_inventory_unit_id)))
            ->with('delivery')
            ->orderByDesc('confirmed_at')
            ->get();
    }

    public function recordCheck(
        EquipmentInstallation $installation,
        string $checkKey,
        InstallationCheckResult $result,
        User $actor,
        ?string $measuredValue = null,
        ?string $unit = null,
        ?string $notes = null,
    ): EquipmentInstallationCheck {
        Gate::forUser($actor)->authorize('update', $installation);

        return DB::transaction(function () use ($installation, $checkKey, $result, $measuredValue, $unit, $notes): EquipmentInstallationCheck {
            $locked = $this->lockedMutable($installation);

            $check = $locked->checks()->where('check_key', $checkKey)->first();

            if (! $check instanceof EquipmentInstallationCheck) {
                throw ValidationException::withMessages(['check_key' => 'Unknown installation check.']);
            }

            $check->update([
                'result' => $result,
                'measured_value' => $measuredValue,
                'unit' => $unit,
                'notes' => $notes,
            ]);

            return $check;
        });
    }

    public function completeInstallation(
        EquipmentInstallation $installation,
        User $actor,
        ?CarbonInterface $installedAt = null,
    ): EquipmentInstallation {
        Gate::forUser($actor)->authorize('complete', $installation);

        return DB::transaction(function () use ($installation, $actor, $installedAt): EquipmentInstallation {
            $locked = $this->lockedMutable($installation);

            if ($locked->isInstalled()) {
                throw ValidationException::withMessages(['installed_at' => 'This installation has already been completed.']);
            }

            $this->assertCustomerMatches($locked);

            $locked->update([
                'installed_at' => $installedAt ?? now(),
                'installed_by_employee_id' => $actor->employeeProfile?->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->warrantyActivation->activateForInstallation($locked);
            $this->log($locked, $actor, 'support.installation.completed');
            $this->notify($locked, NotificationEventKey::InstallationCompleted);

            return $locked;
        });
    }

    public function completeCommissioning(
        EquipmentInstallation $installation,
        User $actor,
        ?CarbonInterface $commissionedAt = null,
    ): EquipmentInstallation {
        Gate::forUser($actor)->authorize('complete', $installation);

        return DB::transaction(function () use ($installation, $actor, $commissionedAt): EquipmentInstallation {
            $locked = $this->lockedMutable($installation);

            if (! $locked->isInstalled()) {
                throw ValidationException::withMessages(['commissioning_status' => 'The installation must be completed before commissioning.']);
            }

            if ($locked->commissioning_status === CommissioningStatus::Passed) {
                throw ValidationException::withMessages(['commissioning_status' => 'Commissioning has already passed.']);
            }

            $this->assertCustomerMatches($locked);

            $unsatisfied = $locked->checks()
                ->whereNotIn('result', [InstallationCheckResult::Passed->value, InstallationCheckResult::NotApplicable->value])
                ->exists();

            if ($unsatisfied) {
                throw ValidationException::withMessages(['commissioning_status' => 'Every installation check must be passed or not applicable before commissioning can pass.']);
            }

            $locked->update([
                'commissioning_status' => CommissioningStatus::Passed,
                'commissioned_at' => $commissionedAt ?? now(),
                'commissioned_by_employee_id' => $actor->employeeProfile?->getKey(),
                'commissioning_failure_reason' => null,
                'updated_by' => $actor->getKey(),
            ]);

            $this->warrantyActivation->activateForCommissioning($locked);
            $this->log($locked, $actor, 'support.commissioning.completed');
            $this->notify($locked, NotificationEventKey::CommissioningPassed);

            return $locked;
        });
    }

    public function failCommissioning(EquipmentInstallation $installation, User $actor, string $reason): EquipmentInstallation
    {
        Gate::forUser($actor)->authorize('complete', $installation);

        if (mb_trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required when commissioning fails.']);
        }

        return DB::transaction(function () use ($installation, $actor, $reason): EquipmentInstallation {
            $locked = $this->lockedMutable($installation);

            if (! $locked->isInstalled()) {
                throw ValidationException::withMessages(['commissioning_status' => 'The installation must be completed before commissioning.']);
            }

            if ($locked->commissioning_status === CommissioningStatus::Passed) {
                throw ValidationException::withMessages(['commissioning_status' => 'Commissioning has already passed.']);
            }

            $locked->update([
                'commissioning_status' => CommissioningStatus::Failed,
                'commissioned_at' => null,
                'commissioning_failure_reason' => $reason,
                'updated_by' => $actor->getKey(),
            ]);

            $this->log($locked, $actor, 'support.commissioning.failed', ['reason' => $reason]);
            $this->notify($locked, NotificationEventKey::CommissioningFailed);

            return $locked;
        });
    }

    public function acceptByCustomer(
        EquipmentInstallation $installation,
        string $signatoryName,
        User $actor,
        ?CarbonInterface $acceptedAt = null,
    ): EquipmentInstallation {
        Gate::forUser($actor)->authorize('complete', $installation);

        if (mb_trim($signatoryName) === '') {
            throw ValidationException::withMessages(['customer_signatory_name' => 'The customer signatory name is required.']);
        }

        return DB::transaction(function () use ($installation, $signatoryName, $actor, $acceptedAt): EquipmentInstallation {
            $locked = $this->lockedAcceptable($installation);

            $locked->update([
                'customer_acceptance_status' => CustomerAcceptanceStatus::Accepted,
                'customer_signatory_name' => $signatoryName,
                'customer_accepted_at' => $acceptedAt ?? now(),
                'customer_rejection_reason' => null,
                'customer_rejected_at' => null,
                'updated_by' => $actor->getKey(),
            ]);

            $this->log($locked, $actor, 'support.installation.accepted');
            $this->notify($locked, NotificationEventKey::CustomerAcceptanceRecorded);

            return $locked;
        });
    }

    public function rejectByCustomer(EquipmentInstallation $installation, string $reason, User $actor): EquipmentInstallation
    {
        Gate::forUser($actor)->authorize('complete', $installation);

        if (mb_trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required when the customer rejects the installation.']);
        }

        return DB::transaction(function () use ($installation, $reason, $actor): EquipmentInstallation {
            $locked = $this->lockedAcceptable($installation);

            $locked->update([
                'customer_acceptance_status' => CustomerAcceptanceStatus::Rejected,
                'customer_accepted_at' => null,
                'customer_rejection_reason' => $reason,
                'customer_rejected_at' => now(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->log($locked, $actor, 'support.installation.rejected', ['reason' => $reason]);
            $this->notify($locked, NotificationEventKey::CustomerAcceptanceRecorded);

            return $locked;
        });
    }

    private function lockedMutable(EquipmentInstallation $installation): EquipmentInstallation
    {
        $locked = EquipmentInstallation::query()->whereKey($installation->getKey())->lockForUpdate()->firstOrFail();
        $record = MaintenanceRecord::query()->findOrFail($locked->maintenance_record_id);

        if ($record->isFinalised()) {
            throw ValidationException::withMessages(['maintenance_record_id' => 'The maintenance request is closed or cancelled.']);
        }

        return $locked;
    }

    private function lockedAcceptable(EquipmentInstallation $installation): EquipmentInstallation
    {
        $locked = $this->lockedMutable($installation);

        if ($locked->commissioning_status !== CommissioningStatus::Passed) {
            throw ValidationException::withMessages(['customer_acceptance_status' => 'Customer acceptance requires successful commissioning.']);
        }

        if ($locked->customer_acceptance_status !== CustomerAcceptanceStatus::Pending) {
            throw ValidationException::withMessages(['customer_acceptance_status' => 'The customer has already responded to this installation.']);
        }

        return $locked;
    }

    private function assertCustomerMatches(EquipmentInstallation $installation): void
    {
        $record = MaintenanceRecord::query()->findOrFail($installation->maintenance_record_id);
        $unit = SerializedInventoryUnit::query()->findOrFail($installation->serialized_inventory_unit_id);

        if ($record->serialized_inventory_unit_id !== $unit->getKey()) {
            throw ValidationException::withMessages(['serialized_inventory_unit_id' => 'The installed equipment no longer matches the maintenance request.']);
        }

        $this->assertUnitBelongsToCustomer($unit, (int) $record->customer_id);
    }

    private function assertUnitBelongsToCustomer(SerializedInventoryUnit $unit, int $customerId): void
    {
        if ($unit->custody_type !== SerializedCustodyType::Customer || (int) $unit->custody_reference_id !== $customerId) {
            throw ValidationException::withMessages(['serialized_inventory_unit_id' => 'The equipment is not in the custody of the maintenance request customer.']);
        }
    }

    private function assertShipmentDeliveredUnit(Shipment $shipment, SerializedInventoryUnit $unit, int $customerId): void
    {
        if (! $shipment->isArrived() || $shipment->confirmed_at === null) {
            throw ValidationException::withMessages(['shipment_id' => 'The delivery shipment has not been confirmed as arrived.']);
        }

        if ((int) $shipment->delivery?->customer_id !== $customerId) {
            throw ValidationException::withMessages(['shipment_id' => 'The shipment was delivered to a different customer.']);
        }

        $delivered = $shipment->delivery?->movements()
            ->where('serialized_inventory_unit_id', $unit->getKey())
            ->exists() ?? false;

        if (! $delivered) {
            throw ValidationException::withMessages(['shipment_id' => 'The shipment did not deliver this equipment.']);
        }
    }

    private function hasConflictingInstallation(SerializedInventoryUnit $unit, MaintenanceRecord $record): bool
    {
        return EquipmentInstallation::query()
            ->where('serialized_inventory_unit_id', $unit->getKey())
            ->where('maintenance_record_id', '!=', $record->getKey())
            ->where('customer_acceptance_status', '!=', CustomerAcceptanceStatus::Rejected->value)
            ->whereHas('maintenanceRecord', static fn (Builder $query): Builder => $query->where('status', '!=', MaintenanceStatus::Cancelled->value))
            ->exists();
    }

    private function notify(EquipmentInstallation $installation, NotificationEventKey $key): void
    {
        $record = MaintenanceRecord::query()->findOrFail($installation->maintenance_record_id);

        DB::afterCommit(static fn () => EquipmentInstallationMilestone::dispatch($record, $key));
    }

    /** @param array<string, mixed> $properties */
    private function log(EquipmentInstallation $installation, User $actor, string $event, array $properties = []): void
    {
        activity()
            ->performedOn($installation)
            ->causedBy($actor)
            ->withProperties([
                'source_channel' => 'dashboard',
                'maintenance_record_id' => $installation->maintenance_record_id,
                'serialized_inventory_unit_id' => $installation->serialized_inventory_unit_id,
                ...$properties,
            ])
            ->log($event);
    }
}
