<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\EquipmentLoanStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\EquipmentLoan;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Inventory\InventoryEquipmentLoanService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates a temporary replacement (loaner) for a customer's unit under
 * repair. Support records the loan; custody changes are requested from
 * {@see InventoryEquipmentLoanService}, never written here, and the actor must
 * hold the Inventory permission as well as the Support one.
 */
final readonly class EquipmentLoanService
{
    public function __construct(private InventoryEquipmentLoanService $inventory) {}

    /**
     * Loaners that may be reserved for this request: the same product as the
     * customer's unit, held available and saleable in a warehouse, and not
     * already on an active loan.
     *
     * @return Collection<int, SerializedInventoryUnit>
     */
    public function eligibleLoaners(MaintenanceRecord $record): Collection
    {
        $original = $record->serializedInventoryUnit()->with('productVariant')->first();

        if (! $original instanceof SerializedInventoryUnit || $original->productVariant === null) {
            return new Collection;
        }

        return SerializedInventoryUnit::query()
            ->whereKeyNot($original->getKey())
            ->where('status', SerializedInventoryUnitStatus::Available->value)
            ->where('custody_type', SerializedCustodyType::Warehouse->value)
            ->where('stock_condition', StockCondition::Saleable->value)
            ->whereNull('inventory_lot_id')
            ->whereHas('productVariant', static fn (Builder $variant): Builder => $variant->where('product_id', $original->productVariant->product_id))
            ->whereNotIn('id', EquipmentLoan::query()->whereIn('status', EquipmentLoanStatus::activeValues())->select('loaner_serialized_inventory_unit_id'))
            ->with('productVariant', 'warehouse')
            ->orderBy('serial_number')
            ->get();
    }

    public function reserve(MaintenanceRecord $record, SerializedInventoryUnit $loaner, User $actor, ?CarbonInterface $expectedReturnAt = null, ?string $notes = null): EquipmentLoan
    {
        Gate::forUser($actor)->authorize('create', EquipmentLoan::class);

        return DB::transaction(function () use ($record, $loaner, $actor, $expectedReturnAt, $notes): EquipmentLoan {
            $locked = MaintenanceRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isFinalised()) {
                throw ValidationException::withMessages(['maintenance_record_id' => 'A closed or cancelled maintenance request cannot reserve a loaner.']);
            }

            if ($locked->serialized_inventory_unit_id === null) {
                throw ValidationException::withMessages(['serialized_inventory_unit_id' => 'The maintenance request must reference serialized equipment.']);
            }

            $original = SerializedInventoryUnit::query()->with('productVariant')->findOrFail($locked->serialized_inventory_unit_id);

            if ($original->custody_type !== SerializedCustodyType::Customer || (int) $original->custody_reference_id !== (int) $locked->customer_id) {
                throw ValidationException::withMessages(['serialized_inventory_unit_id' => 'The equipment is not in the custody of the maintenance request customer.']);
            }

            if (EquipmentLoan::query()->where('maintenance_record_id', $locked->getKey())->whereIn('status', EquipmentLoanStatus::activeValues())->exists()) {
                throw ValidationException::withMessages(['loaner_serialized_inventory_unit_id' => 'This maintenance request already has an active loaner.']);
            }

            $unit = SerializedInventoryUnit::query()->whereKey($loaner->getKey())->lockForUpdate()->firstOrFail();

            if ($unit->is($original)) {
                throw ValidationException::withMessages(['loaner_serialized_inventory_unit_id' => "The customer's own equipment cannot be its loaner."]);
            }

            if (EquipmentLoan::query()->where('loaner_serialized_inventory_unit_id', $unit->getKey())->whereIn('status', EquipmentLoanStatus::activeValues())->exists()) {
                throw ValidationException::withMessages(['loaner_serialized_inventory_unit_id' => 'This loaner already has an active loan.']);
            }

            if (
                $unit->status !== SerializedInventoryUnitStatus::Available
                || $unit->custody_type !== SerializedCustodyType::Warehouse
                || $unit->stock_condition !== StockCondition::Saleable
            ) {
                throw ValidationException::withMessages(['loaner_serialized_inventory_unit_id' => 'The loaner must be available and held in a warehouse.']);
            }

            if ($unit->productVariant?->product_id !== $original->productVariant?->product_id) {
                throw ValidationException::withMessages(['loaner_serialized_inventory_unit_id' => "The loaner must be the same product as the customer's equipment."]);
            }

            $loan = EquipmentLoan::query()->create([
                'maintenance_record_id' => $locked->getKey(),
                'customer_id' => $locked->customer_id,
                'original_serialized_inventory_unit_id' => $original->getKey(),
                'loaner_serialized_inventory_unit_id' => $unit->getKey(),
                'status' => EquipmentLoanStatus::Reserved,
                'reserved_at' => now(),
                'expected_return_at' => $expectedReturnAt,
                'notes' => $notes,
            ]);

            $this->log($loan, $actor, 'support.loaner.reserved');

            return $loan;
        });
    }

    public function issue(EquipmentLoan $loan, User $actor, ?CarbonInterface $expectedReturnAt = null): EquipmentLoan
    {
        Gate::forUser($actor)->authorize('update', $loan);

        return DB::transaction(function () use ($loan, $actor, $expectedReturnAt): EquipmentLoan {
            $locked = $this->locked($loan);

            if ($locked->status !== EquipmentLoanStatus::Reserved) {
                throw ValidationException::withMessages(['status' => 'Only a reserved loan can be issued.']);
            }

            if (MaintenanceRecord::query()->findOrFail($locked->maintenance_record_id)->isFinalised()) {
                throw ValidationException::withMessages(['maintenance_record_id' => 'The maintenance request is closed or cancelled.']);
            }

            $due = $expectedReturnAt ?? $locked->expected_return_at;

            if ($due === null || $due->isPast()) {
                throw ValidationException::withMessages(['expected_return_at' => 'An expected return date in the future is required to issue a loaner.']);
            }

            $unit = SerializedInventoryUnit::query()->findOrFail($locked->loaner_serialized_inventory_unit_id);
            $condition = $unit->stock_condition;
            $movement = $this->inventory->issue($unit, $locked->customer_id, $locked->id, $actor, 'Loaner issued for maintenance request #'.$locked->maintenance_record_id);

            $locked->update([
                'status' => EquipmentLoanStatus::Issued,
                'issued_at' => now(),
                'expected_return_at' => $due,
                'condition_out' => $condition,
                'issue_inventory_movement_id' => $movement->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->log($locked, $actor, 'support.loaner.issued', ['inventory_movement_id' => $movement->getKey()]);

            return $locked;
        });
    }

    /** Returns the loaner to a warehouse; recording a return stays possible after the request is closed. */
    public function recordReturn(EquipmentLoan $loan, int $warehouseId, StockCondition $conditionIn, User $actor, ?string $notes = null): EquipmentLoan
    {
        Gate::forUser($actor)->authorize('update', $loan);

        return DB::transaction(function () use ($loan, $warehouseId, $conditionIn, $actor, $notes): EquipmentLoan {
            $locked = $this->locked($loan);

            if ($locked->status !== EquipmentLoanStatus::Issued) {
                throw ValidationException::withMessages(['status' => 'Only an issued loan can be returned.']);
            }

            $unit = SerializedInventoryUnit::query()->findOrFail($locked->loaner_serialized_inventory_unit_id);
            $movement = $this->inventory->return($unit, $warehouseId, $conditionIn, $locked->id, $actor, $notes);

            $locked->update([
                'status' => EquipmentLoanStatus::Returned,
                'returned_at' => now(),
                'condition_in' => $conditionIn,
                'return_inventory_movement_id' => $movement->getKey(),
                'notes' => $notes ?? $locked->notes,
                'updated_by' => $actor->getKey(),
            ]);

            $this->log($locked, $actor, 'support.loaner.returned', ['condition_in' => $conditionIn->value, 'inventory_movement_id' => $movement->getKey()]);

            return $locked;
        });
    }

    public function cancel(EquipmentLoan $loan, User $actor, string $reason): EquipmentLoan
    {
        Gate::forUser($actor)->authorize('update', $loan);

        if (mb_trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required to cancel a loan.']);
        }

        return DB::transaction(function () use ($loan, $actor, $reason): EquipmentLoan {
            $locked = $this->locked($loan);

            if ($locked->status !== EquipmentLoanStatus::Reserved) {
                throw ValidationException::withMessages(['status' => 'Only a reserved loan can be cancelled; an issued loan must be returned.']);
            }

            $locked->update(['status' => EquipmentLoanStatus::Cancelled, 'notes' => $reason, 'updated_by' => $actor->getKey()]);
            $this->log($locked, $actor, 'support.loaner.cancelled', ['reason' => $reason]);

            return $locked;
        });
    }

    private function locked(EquipmentLoan $loan): EquipmentLoan
    {
        return EquipmentLoan::query()->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $properties */
    private function log(EquipmentLoan $loan, User $actor, string $event, array $properties = []): void
    {
        activity()
            ->performedOn($loan)
            ->causedBy($actor)
            ->withProperties([
                'source_channel' => 'dashboard',
                'maintenance_record_id' => $loan->maintenance_record_id,
                'loaner_serialized_inventory_unit_id' => $loan->loaner_serialized_inventory_unit_id,
                ...$properties,
            ])
            ->log($event);
    }
}
