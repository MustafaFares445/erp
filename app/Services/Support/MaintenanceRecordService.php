<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\WarrantyCoverage;
use App\Enums\MaintenanceStatus;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketServicePath;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class MaintenanceRecordService
{
    public function __construct(private WarrantyResolver $warrantyResolver) {}

    /** @param array<string, mixed> $data */
    public function createFromTicket(Ticket $ticket, array $data, User $actor): MaintenanceRecord
    {
        Gate::forUser($actor)->authorize('create', MaintenanceRecord::class);

        if ($ticket->triaged_at === null || $ticket->service_path !== TicketServicePath::Maintenance) {
            throw ValidationException::withMessages([
                'ticket_id' => 'The ticket must be triaged to the maintenance service path before a maintenance request can be raised.',
            ]);
        }

        return DB::transaction(function () use ($ticket, $data, $actor): MaintenanceRecord {
            $serial = $ticket->equipment_source === TicketEquipmentSource::External
                ? $ticket->external_serial_number
                : $ticket->serializedInventoryUnit?->serial_number;

            $record = MaintenanceRecord::query()->create([
                'customer_id' => $ticket->customer_id,
                'ticket_id' => $ticket->getKey(),
                'product_variant_id' => $ticket->serializedInventoryUnit?->product_variant_id,
                'serial_number' => $serial,
                'serialized_inventory_unit_id' => $ticket->serialized_inventory_unit_id,
                'is_equipment_unlinked' => $ticket->equipment_source === TicketEquipmentSource::External && filled($serial),
                'warranty_status' => $ticket->warranty_status ?? WarrantyStatus::Unknown,
                'warranty_expiry_date' => $ticket->warranty_expiry_date,
                'description' => $data['description'] ?? $ticket->description,
                'status' => MaintenanceStatus::Open,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->logCreated($record, $actor);

            return $record;
        });
    }

    /** @param array<string, mixed> $data */
    public function createStandalone(array $data, User $actor): MaintenanceRecord
    {
        Gate::forUser($actor)->authorize('create', MaintenanceRecord::class);

        return DB::transaction(function () use ($data, $actor): MaintenanceRecord {
            $record = MaintenanceRecord::query()->create([
                'customer_id' => $data['customer_id'],
                'ticket_id' => null,
                'description' => $data['description'],
                'status' => MaintenanceStatus::Open,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                ...$this->resolveStandaloneEquipment($data),
            ]);

            $this->logCreated($record, $actor);

            return $record;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(MaintenanceRecord $record, array $data, User $actor): MaintenanceRecord
    {
        Gate::forUser($actor)->authorize('update', $record);

        return DB::transaction(function () use ($record, $data, $actor): MaintenanceRecord {
            $oldValues = $record->only(['serial_number', 'warranty_status', 'warranty_expiry_date', 'description']);
            $attributes = [
                'description' => $data['description'] ?? $record->description,
                'updated_by' => $actor->getKey(),
            ];

            if ($record->ticket_id === null) {
                $attributes = [...$attributes, ...$this->resolveStandaloneEquipment([
                    ...$data,
                    'customer_id' => $record->customer_id,
                    'serial_number' => $data['serial_number'] ?? $record->serial_number,
                ])];
            }

            $record->update($attributes);

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

    public function overrideWarranty(
        MaintenanceRecord $record,
        WarrantyStatus $status,
        ?CarbonInterface $expiry,
        string $reason,
        User $actor,
    ): MaintenanceRecord {
        Gate::forUser($actor)->authorize('update', $record);

        if (mb_trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required to override warranty coverage.']);
        }

        if ($status === WarrantyStatus::Covered && ! $expiry instanceof CarbonInterface) {
            throw ValidationException::withMessages(['warranty_expiry_date' => 'A covered warranty requires an expiry date.']);
        }

        return DB::transaction(function () use ($record, $status, $expiry, $reason, $actor): MaintenanceRecord {
            $old = $record->only(['warranty_status', 'warranty_expiry_date']);
            $record->update([
                'warranty_status' => $status,
                'warranty_expiry_date' => $expiry?->toDateString(),
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($record)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $old,
                    'attributes' => [
                        'warranty_status' => $status->value,
                        'warranty_expiry_date' => $expiry?->toDateString(),
                    ],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip(), 'reason' => $reason])
                ->log('support.maintenance_record.warranty_overridden');

            return $record->refresh();
        });
    }

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

            if ($to === MaintenanceStatus::Closed) {
                app(MaintenanceScheduleGenerator::class)->completeForRecord($record);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveStandaloneEquipment(array $data): array
    {
        $serial = $data['serial_number'] ?? null;
        $serial = is_string($serial) ? mb_trim($serial) : null;
        $serial = $serial === '' ? null : $serial;

        $unit = null;
        if ($serial !== null) {
            $unit = SerializedInventoryUnit::query()
                ->with('productVariant')
                ->whereRaw('LOWER(serial_number) = ?', [mb_strtolower($serial)])
                ->first();
        }

        $explicitStatus = $this->explicitWarrantyStatus($data);
        $explicitExpiry = $data['warranty_expiry_date'] ?? null;

        if ($explicitStatus === WarrantyStatus::Covered && empty($explicitExpiry)) {
            throw ValidationException::withMessages([
                'warranty_expiry_date' => 'A warranty expiry date is required when warranty is covered.',
            ]);
        }

        $customerId = $data['customer_id'] ?? null;
        $coverage = null;

        if (! $explicitStatus instanceof WarrantyStatus && $unit instanceof SerializedInventoryUnit && is_numeric($customerId)) {
            $customer = CustomerProfile::query()->find((int) $customerId);
            if ($customer !== null) {
                $coverage = $this->warrantyResolver->resolveForSerializedUnit($unit, $customer);
            }
        }

        if (! $explicitStatus instanceof WarrantyStatus && $coverage === null && $serial !== null && ! $unit instanceof SerializedInventoryUnit) {
            $coverage = $this->warrantyResolver->externalEquipment();
        }

        $productVariantId = $unit instanceof SerializedInventoryUnit
            ? $unit->product_variant_id
            : ($data['product_variant_id'] ?? null);
        $serializedUnitId = $unit instanceof SerializedInventoryUnit ? $unit->getKey() : null;
        $warrantyStatus = $explicitStatus;
        $warrantyExpiry = $explicitExpiry ?: null;

        if (! $warrantyStatus instanceof WarrantyStatus && $coverage instanceof WarrantyCoverage) {
            $warrantyStatus = $coverage->status;
            $warrantyExpiry = $coverage->expiresOn?->toDateString();
        }

        return [
            'product_variant_id' => $productVariantId,
            'serial_number' => $serial,
            'serialized_inventory_unit_id' => $serializedUnitId,
            'is_equipment_unlinked' => $serial !== null && $unit === null,
            'warranty_status' => $warrantyStatus ?? WarrantyStatus::Unknown,
            'warranty_expiry_date' => $warrantyExpiry,
        ];
    }

    /** @param array<string, mixed> $data */
    private function explicitWarrantyStatus(array $data): ?WarrantyStatus
    {
        if (! array_key_exists('warranty_status', $data)) {
            return null;
        }

        $raw = $data['warranty_status'];

        if ($raw instanceof WarrantyStatus) {
            return $raw;
        }

        if (! is_string($raw)) {
            return WarrantyStatus::Unknown;
        }

        return WarrantyStatus::tryFrom($raw) ?? WarrantyStatus::Unknown;
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
