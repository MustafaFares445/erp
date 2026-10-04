<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\ExternalRepairStatus;
use App\Enums\NotificationEventKey;
use App\Enums\SerializedCustodyType;
use App\Enums\StockCondition;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyRecoveryOutcome;
use App\Events\SupportContinuityMilestone;
use App\Models\MaintenanceExternalRepair;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Models\WarrantyRecoveryClaim;
use App\Services\Inventory\InventorySupplierCustodyService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates a supplier / manufacturer repair (RMA) as a contextual part of
 * a maintenance request. Support owns the service facts and statuses;
 * supplier custody is requested from {@see InventorySupplierCustodyService},
 * never written here, and the actor needs the Inventory permission for those
 * two steps. Recovery stays with the existing warranty-recovery claim.
 */
final readonly class ExternalRepairService
{
    public function __construct(
        private InventorySupplierCustodyService $inventory,
        private WarrantyEntitlementService $entitlements,
    ) {}

    /** @param array{rma_number?: string|null, reason?: string|null, estimated_return_on?: CarbonInterface|null} $data */
    public function request(MaintenanceRecord $record, Supplier $supplier, User $actor, array $data = []): MaintenanceExternalRepair
    {
        Gate::forUser($actor)->authorize('create', MaintenanceExternalRepair::class);

        return DB::transaction(function () use ($record, $supplier, $actor, $data): MaintenanceExternalRepair {
            $locked = MaintenanceRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isFinalised()) {
                throw ValidationException::withMessages(['maintenance_record_id' => 'A closed or cancelled maintenance request cannot start a supplier repair.']);
            }

            if ($locked->serialized_inventory_unit_id === null) {
                throw ValidationException::withMessages(['serialized_inventory_unit_id' => 'The maintenance request must reference serialized equipment.']);
            }

            if (! $supplier->is_active) {
                throw ValidationException::withMessages(['supplier_id' => 'Choose an active supplier.']);
            }

            $unit = SerializedInventoryUnit::query()->findOrFail($locked->serialized_inventory_unit_id);
            $withCustomer = $unit->custody_type === SerializedCustodyType::Customer && (int) $unit->custody_reference_id === (int) $locked->customer_id;

            if (! $withCustomer && $unit->custody_type !== SerializedCustodyType::Warehouse) {
                throw ValidationException::withMessages(['serialized_inventory_unit_id' => 'The equipment must be with the maintenance request customer or in a warehouse.']);
            }

            $open = MaintenanceExternalRepair::query()
                ->where('serialized_inventory_unit_id', $unit->getKey())
                ->whereIn('status', ExternalRepairStatus::openValues())
                ->exists();

            if ($open) {
                throw ValidationException::withMessages(['serialized_inventory_unit_id' => 'An open supplier repair already exists for this equipment.']);
            }

            $repair = MaintenanceExternalRepair::query()->create([
                'maintenance_record_id' => $locked->getKey(),
                'serialized_inventory_unit_id' => $unit->getKey(),
                'supplier_id' => $supplier->getKey(),
                'rma_number' => $data['rma_number'] ?? null,
                'status' => ExternalRepairStatus::Requested,
                'requested_at' => now(),
                'reason' => $data['reason'] ?? null,
                'estimated_return_on' => $data['estimated_return_on'] ?? null,
            ]);

            $this->log($repair, $actor, 'support.rma.requested');
            $this->notify($repair);

            return $repair;
        });
    }

    public function approve(MaintenanceExternalRepair $repair, User $actor, ?string $rmaNumber = null): MaintenanceExternalRepair
    {
        return $this->advance($repair, ExternalRepairStatus::Approved, $actor, 'support.rma.approved', static fn (MaintenanceExternalRepair $locked): array => [
            'approved_at' => now(),
            'rma_number' => $rmaNumber ?? $locked->rma_number,
        ]);
    }

    /** Warehouse custody -> supplier custody; requires the Inventory permission too. */
    public function ship(MaintenanceExternalRepair $repair, User $actor, ?string $outboundReference = null): MaintenanceExternalRepair
    {
        return $this->advance($repair, ExternalRepairStatus::ShippedToSupplier, $actor, 'support.rma.shipped', function (MaintenanceExternalRepair $locked) use ($actor, $outboundReference): array {
            $movement = $this->inventory->shipToSupplier(
                SerializedInventoryUnit::query()->findOrFail($locked->serialized_inventory_unit_id),
                $locked->supplier_id,
                $locked->id,
                $actor,
                'Sent to supplier for external repair (maintenance request #'.$locked->maintenance_record_id.')',
            );

            return [
                'shipped_at' => now(),
                'outbound_reference' => $outboundReference,
                'ship_inventory_movement_id' => $movement->getKey(),
            ];
        });
    }

    public function markReceivedBySupplier(MaintenanceExternalRepair $repair, User $actor, ?string $supplierReference = null): MaintenanceExternalRepair
    {
        return $this->advance($repair, ExternalRepairStatus::ReceivedBySupplier, $actor, 'support.rma.received', static fn (MaintenanceExternalRepair $locked): array => [
            'supplier_received_at' => now(),
            'supplier_reference' => $supplierReference ?? $locked->supplier_reference,
        ]);
    }

    public function startRepair(MaintenanceExternalRepair $repair, User $actor, ?string $diagnosis = null, ?CarbonInterface $estimatedReturnOn = null): MaintenanceExternalRepair
    {
        return $this->advance($repair, ExternalRepairStatus::Repairing, $actor, 'support.rma.repairing', static fn (MaintenanceExternalRepair $locked): array => [
            'supplier_diagnosis' => $diagnosis ?? $locked->supplier_diagnosis,
            'estimated_return_on' => $estimatedReturnOn ?? $locked->estimated_return_on,
        ]);
    }

    public function recordRepaired(MaintenanceExternalRepair $repair, User $actor, string $resolution): MaintenanceExternalRepair
    {
        $this->requireText($resolution, 'supplier_resolution', 'The supplier resolution is required.');

        return $this->advance($repair, ExternalRepairStatus::Repaired, $actor, 'support.rma.repaired', static fn (): array => [
            'supplier_resolution' => $resolution,
            'completed_at' => now(),
        ]);
    }

    public function approveReplacement(MaintenanceExternalRepair $repair, User $actor, string $resolution): MaintenanceExternalRepair
    {
        $this->requireText($resolution, 'supplier_resolution', 'The supplier resolution is required.');

        return $this->advance($repair, ExternalRepairStatus::ReplacementApproved, $actor, 'support.rma.replacement_approved', static fn (): array => [
            'supplier_resolution' => $resolution,
            'completed_at' => now(),
        ]);
    }

    /**
     * Links the supplier's replacement unit (registered by Inventory and
     * already delivered to the same customer) and carries the original
     * unit's active warranty to it under the policy's replacement rule.
     */
    public function recordReplacementReceived(MaintenanceExternalRepair $repair, SerializedInventoryUnit $replacement, User $actor): MaintenanceExternalRepair
    {
        return $this->advance($repair, ExternalRepairStatus::ReplacementReceived, $actor, 'support.rma.replaced', function (MaintenanceExternalRepair $locked) use ($replacement, $actor): array {
            $original = SerializedInventoryUnit::query()->with('productVariant')->findOrFail($locked->serialized_inventory_unit_id);
            $record = MaintenanceRecord::query()->findOrFail($locked->maintenance_record_id);
            $replacement->loadMissing('productVariant');

            if ($replacement->is($original)) {
                throw ValidationException::withMessages(['replacement_serialized_inventory_unit_id' => 'Choose a different serialized unit as the replacement.']);
            }

            if ($replacement->productVariant?->product_id !== $original->productVariant?->product_id) {
                throw ValidationException::withMessages(['replacement_serialized_inventory_unit_id' => 'The replacement must be the same product as the original equipment.']);
            }

            if ($replacement->custody_type !== SerializedCustodyType::Customer || (int) $replacement->custody_reference_id !== (int) $record->customer_id) {
                throw ValidationException::withMessages(['replacement_serialized_inventory_unit_id' => 'The replacement serial must already be in the same customer custody.']);
            }

            if (MaintenanceExternalRepair::query()->where('replacement_serialized_inventory_unit_id', $replacement->getKey())->whereKeyNot($locked->getKey())->exists()) {
                throw ValidationException::withMessages(['replacement_serialized_inventory_unit_id' => 'This unit already replaces other equipment.']);
            }

            $entitlement = WarrantyEntitlement::query()
                ->where('serialized_inventory_unit_id', $original->getKey())
                ->where('customer_id', $record->customer_id)
                ->where('state', WarrantyEntitlementState::Active->value)
                ->latest('id')
                ->first();

            if ($entitlement instanceof WarrantyEntitlement) {
                $this->entitlements->applyReplacement($entitlement, $replacement, $actor, 'Supplier replacement under RMA '.($locked->rma_number ?? '#'.$locked->id));
            }

            return ['replacement_serialized_inventory_unit_id' => $replacement->getKey(), 'returned_at' => now()];
        });
    }

    /** Supplier custody -> warehouse custody after a repair; requires the Inventory permission too. */
    public function returnToCompany(MaintenanceExternalRepair $repair, int $warehouseId, StockCondition $condition, User $actor, ?string $inboundReference = null): MaintenanceExternalRepair
    {
        return $this->advance($repair, ExternalRepairStatus::ReturnedToCompany, $actor, 'support.rma.returned', function (MaintenanceExternalRepair $locked) use ($warehouseId, $condition, $actor, $inboundReference): array {
            $movement = $this->inventory->receiveFromSupplier(
                SerializedInventoryUnit::query()->findOrFail($locked->serialized_inventory_unit_id),
                $warehouseId,
                $condition,
                $locked->id,
                $actor,
                'Received from supplier repair (maintenance request #'.$locked->maintenance_record_id.')',
            );

            return [
                'returned_at' => now(),
                'actual_return_on' => now()->toDateString(),
                'inbound_reference' => $inboundReference,
                'return_inventory_movement_id' => $movement->getKey(),
            ];
        });
    }

    public function cancel(MaintenanceExternalRepair $repair, User $actor, string $reason): MaintenanceExternalRepair
    {
        $this->requireText($reason, 'reason', 'A reason is required to cancel a supplier repair.');

        return $this->advance($repair, ExternalRepairStatus::Cancelled, $actor, 'support.rma.cancelled', static fn (): array => ['cancellation_reason' => $reason], ['reason' => $reason]);
    }

    /** Links the existing warranty-recovery claim of the same maintenance request; no second claim system. */
    public function linkRecoveryClaim(MaintenanceExternalRepair $repair, WarrantyRecoveryClaim $claim, User $actor): MaintenanceExternalRepair
    {
        Gate::forUser($actor)->authorize('update', $repair);

        if ($claim->maintenance_record_id !== $repair->maintenance_record_id) {
            throw ValidationException::withMessages(['warranty_recovery_claim_id' => 'The recovery claim belongs to a different maintenance request.']);
        }

        $repair->update(['warranty_recovery_claim_id' => $claim->getKey(), 'updated_by' => $actor->getKey()]);
        $this->log($repair, $actor, 'support.rma.recovery_linked', ['warranty_recovery_claim_id' => $claim->getKey()]);

        return $repair;
    }

    /** Records how the supplier settled the linked recovery claim (cash, credit note, replacement unit, parts or rejection). */
    public function recordRecoveryOutcome(MaintenanceExternalRepair $repair, WarrantyRecoveryOutcome $outcome, User $actor): WarrantyRecoveryClaim
    {
        Gate::forUser($actor)->authorize('update', $repair);

        $claim = $repair->warrantyRecoveryClaim;

        if (! $claim instanceof WarrantyRecoveryClaim) {
            throw ValidationException::withMessages(['warranty_recovery_claim_id' => 'Link a warranty recovery claim before recording its outcome.']);
        }

        $claim->update(['recovery_outcome' => $outcome, 'updated_by' => $actor->getKey()]);
        $this->log($repair, $actor, 'support.rma.recovery_outcome', ['outcome' => $outcome->value]);

        return $claim;
    }

    /**
     * Moves the repair to the next legal status under a row lock; `$changes`
     * runs in the same transaction so a custody failure leaves no half-state.
     *
     * @param  callable(MaintenanceExternalRepair): array<string, mixed>  $changes
     * @param  array<string, mixed>  $properties
     */
    private function advance(MaintenanceExternalRepair $repair, ExternalRepairStatus $to, User $actor, string $event, callable $changes, array $properties = []): MaintenanceExternalRepair
    {
        Gate::forUser($actor)->authorize('update', $repair);

        return DB::transaction(function () use ($repair, $to, $actor, $event, $changes, $properties): MaintenanceExternalRepair {
            $locked = MaintenanceExternalRepair::query()->whereKey($repair->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($to, $locked->status->nextStatuses(), true)) {
                throw ValidationException::withMessages(['status' => sprintf('A supplier repair cannot move from %s to %s.', $locked->status->label(), $to->label())]);
            }

            $locked->update([...$changes($locked), 'status' => $to, 'updated_by' => $actor->getKey()]);
            $this->log($locked, $actor, $event, ['status' => $to->value, ...$properties]);
            $this->notify($locked);

            return $locked;
        });
    }

    private function requireText(string $value, string $field, string $message): void
    {
        if (mb_trim($value) === '') {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function notify(MaintenanceExternalRepair $repair): void
    {
        $key = in_array($repair->status, [ExternalRepairStatus::ReturnedToCompany, ExternalRepairStatus::ReplacementReceived], true)
            ? NotificationEventKey::EquipmentReturnedFromSupplier
            : NotificationEventKey::RmaStatusChanged;

        DB::afterCommit(static fn () => SupportContinuityMilestone::dispatch($repair->maintenanceRecord()->firstOrFail(), $key, $repair->id));
    }

    /** @param array<string, mixed> $properties */
    private function log(MaintenanceExternalRepair $repair, User $actor, string $event, array $properties = []): void
    {
        activity()
            ->performedOn($repair)
            ->causedBy($actor)
            ->withProperties([
                'source_channel' => 'dashboard',
                'maintenance_record_id' => $repair->maintenance_record_id,
                'serialized_inventory_unit_id' => $repair->serialized_inventory_unit_id,
                ...$properties,
            ])
            ->log($event);
    }
}
