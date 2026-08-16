<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceStatus;
use App\Enums\WarrantyStatus;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance request creation, equipment link, warranty, and status
 * transitions (FR-060–066, contracts/maintenance-lifecycle.md §1–2).
 */
final readonly class MaintenanceRecordService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createFromTicket(Ticket $ticket, array $data, User $actor): MaintenanceRecord
    {
        Gate::forUser($actor)->authorize('create', MaintenanceRecord::class);

        return DB::transaction(function () use ($ticket, $data, $actor): MaintenanceRecord {
            $record = MaintenanceRecord::query()->create($this->buildAttributes([
                ...$data,
                'ticket_id' => $ticket->getKey(),
                'customer_id' => $ticket->customer_id,
                'description' => $data['description'] ?? $ticket->description,
            ], $actor));

            $this->logCreated($record, $actor);

            return $record;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createStandalone(array $data, User $actor): MaintenanceRecord
    {
        Gate::forUser($actor)->authorize('create', MaintenanceRecord::class);

        return DB::transaction(function () use ($data, $actor): MaintenanceRecord {
            $record = MaintenanceRecord::query()->create($this->buildAttributes([
                ...$data,
                'ticket_id' => null,
            ], $actor));

            $this->logCreated($record, $actor);

            return $record;
        });
    }

    /**
     * Corrects the descriptive fields, equipment link, and warranty — not a
     * status transition (that's {@see self::transition()}).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(MaintenanceRecord $record, array $data, User $actor): MaintenanceRecord
    {
        Gate::forUser($actor)->authorize('update', $record);

        return DB::transaction(function () use ($record, $data, $actor): MaintenanceRecord {
            $oldValues = $record->only(['serial_number', 'warranty_status', 'warranty_expiry_date', 'description']);

            $record->update([
                'description' => $data['description'] ?? $record->description,
                'updated_by' => $actor->getKey(),
                ...$this->resolveEquipmentAndWarranty($data),
            ]);

            activity()
                ->performedOn($record)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $oldValues,
                    'attributes' => $record->only(['serial_number', 'warranty_status', 'warranty_expiry_date', 'description']),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.maintenance_record.updated');

            return $record;
        });
    }

    /**
     * @throws InvalidStatusTransition when `$from->canTransitionTo($to)` is false
     */
    public function transition(MaintenanceRecord $record, MaintenanceStatus $to, User $actor, ?string $note = null): void
    {
        Gate::forUser($actor)->authorize('update', $record);

        $from = $record->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidStatusTransition::fromTo($from->value, $to->value);
        }

        if ($to === MaintenanceStatus::Closed
            && $record->serviceRecords()->whereNotIn('status', [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled])->exists()) {
            throw InvalidStatusTransition::fromTo($from->value, $to->value);
        }

        DB::transaction(function () use ($record, $from, $to, $actor, $note): void {
            $record->update(['status' => $to->value, 'updated_by' => $actor->getKey()]);

            activity()
                ->performedOn($record)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => $from->value],
                    'attributes' => ['status' => $to->value, 'note' => $note],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.maintenance_record.status_changed');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildAttributes(array $data, User $actor): array
    {
        return [
            'customer_id' => $data['customer_id'],
            'ticket_id' => $data['ticket_id'] ?? null,
            'description' => $data['description'],
            'status' => MaintenanceStatus::Open,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
            ...$this->resolveEquipmentAndWarranty($data),
        ];
    }

    /**
     * The equipment-link lookup (FR-062–063) and warranty validation
     * (FR-064), shared by creation and correction alike.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveEquipmentAndWarranty(array $data): array
    {
        $rawWarrantyStatus = $data['warranty_status'] ?? WarrantyStatus::Unknown->value;
        $warrantyStatus = match (true) {
            $rawWarrantyStatus instanceof WarrantyStatus => $rawWarrantyStatus,
            is_string($rawWarrantyStatus) => WarrantyStatus::from($rawWarrantyStatus),
            default => WarrantyStatus::Unknown,
        };

        if ($warrantyStatus === WarrantyStatus::Covered && empty($data['warranty_expiry_date'])) {
            throw ValidationException::withMessages([
                'warranty_expiry_date' => 'A warranty expiry date is required when warranty is covered.',
            ]);
        }

        $serialNumber = $data['serial_number'] ?? null;
        $serialNumber = is_string($serialNumber) ? mb_trim($serialNumber) : null;
        $serialNumber = $serialNumber === '' ? null : $serialNumber;

        $unit = null;

        if ($serialNumber !== null) {
            // Case-insensitive, whitespace-trimmed match (FR-062/063) — a serial entered with
            // different casing or incidental surrounding whitespace than the one on record must
            // still resolve to the same equipment, not silently fall back to "unlinked".
            $matchedUnit = SerializedInventoryUnit::query()
                ->whereRaw('LOWER(serial_number) = ?', [mb_strtolower($serialNumber)])
                ->first();

            if ($matchedUnit instanceof SerializedInventoryUnit) {
                $unit = $matchedUnit;
            }
        }

        return [
            'product_variant_id' => $unit instanceof SerializedInventoryUnit ? $unit->product_variant_id : ($data['product_variant_id'] ?? null),
            'serial_number' => $serialNumber,
            'serialized_inventory_unit_id' => $unit?->getKey(),
            // True only when a serial number was entered but matched no known unit — distinct
            // from having no serial number at all (FR-063's "unlinked equipment" flag).
            'is_equipment_unlinked' => $serialNumber !== null && $unit === null,
            'warranty_status' => $warrantyStatus,
            'warranty_expiry_date' => $data['warranty_expiry_date'] ?? null,
        ];
    }

    private function logCreated(MaintenanceRecord $record, User $actor): void
    {
        activity()
            ->performedOn($record)
            ->causedBy($actor)
            ->withChanges(['attributes' => $record->getAttributes()])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('support.maintenance_record.created');
    }
}
