<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\WarrantyCoverage;
use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketServicePath;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceRecordPart;
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

        if (
            $ticket->triaged_at === null
            || ! in_array($ticket->service_path, [TicketServicePath::Maintenance, TicketServicePath::OnSiteVisit], true)
        ) {
            throw ValidationException::withMessages([
                'ticket_id' => 'The ticket must be triaged to maintenance or an on-site visit before a maintenance request can be raised.',
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
            $locked = $this->lockedMutableRecord($record);
            $oldValues = $locked->only(['serial_number', 'warranty_status', 'warranty_expiry_date', 'description']);
            $attributes = [
                'description' => $data['description'] ?? $locked->description,
                'updated_by' => $actor->getKey(),
            ];

            if ($locked->ticket_id === null) {
                $equipmentData = [
                    ...$data,
                    'customer_id' => $locked->customer_id,
                    'serialized_inventory_unit_id' => $data['serialized_inventory_unit_id'] ?? $locked->serialized_inventory_unit_id,
                    'serial_number' => $data['serial_number'] ?? $locked->serial_number,
                ];

                $customerIdChanged = false;
                if (array_key_exists('customer_id', $data)) {
                    $customerId = $data['customer_id'];

                    if (is_int($customerId) || (is_string($customerId) && is_numeric($customerId))) {
                        $customerIdChanged = (int) $customerId !== (int) $locked->customer_id;
                    } else {
                        $customerIdChanged = true;
                    }
                }

                $serializedInventoryUnitIdChanged = false;
                if (array_key_exists('serialized_inventory_unit_id', $data)) {
                    $serializedInventoryUnitId = $data['serialized_inventory_unit_id'];

                    if ($serializedInventoryUnitId === null) {
                        $serializedInventoryUnitIdChanged = $locked->serialized_inventory_unit_id !== null;
                    } elseif (is_int($serializedInventoryUnitId) || (is_string($serializedInventoryUnitId) && is_numeric($serializedInventoryUnitId))) {
                        $serializedInventoryUnitIdChanged = (int) $serializedInventoryUnitId !== $locked->serialized_inventory_unit_id;
                    } else {
                        $serializedInventoryUnitIdChanged = true;
                    }
                }

                $equipmentChanged = (array_key_exists('serial_number', $data) && $data['serial_number'] !== $locked->serial_number)
                    || $serializedInventoryUnitIdChanged
                    || $customerIdChanged;

                if (! $equipmentChanged) {
                    $equipmentData['warranty_status'] = $locked->warranty_status;
                    $equipmentData['warranty_expiry_date'] = $locked->warranty_expiry_date?->toDateString();
                }

                $attributes = [...$attributes, ...$this->resolveStandaloneEquipment($equipmentData)];
            }

            $locked->update($attributes);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $oldValues,
                    'attributes' => $locked->only(['serial_number', 'warranty_status', 'warranty_expiry_date', 'description']),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.maintenance_record.updated');

            return $record->refresh();
        });
    }

    public function overrideWarranty(
        MaintenanceRecord $record,
        WarrantyStatus $status,
        ?CarbonInterface $expiry,
        string $reason,
        User $actor,
    ): MaintenanceRecord {
        Gate::forUser($actor)->authorize('overrideWarranty', $record);

        if (mb_trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required to override warranty coverage.']);
        }

        if ($status === WarrantyStatus::Covered && ! $expiry instanceof CarbonInterface) {
            throw ValidationException::withMessages(['warranty_expiry_date' => 'A covered warranty requires an expiry date.']);
        }

        return DB::transaction(function () use ($record, $status, $expiry, $reason, $actor): MaintenanceRecord {
            $locked = $this->lockedMutableRecord($record);
            $old = $locked->only(['warranty_status', 'warranty_expiry_date']);
            $locked->update([
                'warranty_status' => $status,
                'warranty_expiry_date' => $expiry?->toDateString(),
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($locked)
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
        Gate::forUser($actor)->authorize('transition', $record);

        DB::transaction(function () use ($record, $to, $actor, $note): void {
            $locked = $this->lockedRecord($record);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }

            if ($from === MaintenanceStatus::AwaitingApproval
                && in_array($to, [MaintenanceStatus::ReadyForRepair, MaintenanceStatus::InProgress], true)
                && (int) $locked->coverageLines()->sum('customer_amount_minor') > 0) {
                $locked->loadMissing('quotation');

                if ($locked->quotation?->status !== QuotationStatus::Accepted) {
                    throw new InvalidStatusTransition('The customer quotation must be accepted before repair can begin.');
                }
            }

            if ($to === MaintenanceStatus::Closed
                && $locked->serviceRecords()->whereNotIn('status', [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled])->exists()) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }

            if ($to === MaintenanceStatus::Cancelled
                && ServiceRecordPart::query()
                    ->whereIn('maintenance_task_id', $locked->serviceRecords()->select('id'))
                    ->whereNull('reversed_at')
                    ->exists()) {
                throw new InvalidStatusTransition('A maintenance request with consumed parts cannot be cancelled until the parts are reversed.');
            }

            $locked->update(['status' => $to->value, 'updated_by' => $actor->getKey()]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => $from->value],
                    'attributes' => ['status' => $to->value, 'note' => $note],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.maintenance_record.status_changed');

            if ($to === MaintenanceStatus::Closed) {
                app(MaintenanceScheduleGenerator::class)->completeForRecord($locked);
            }

            $record->refresh();
        });
    }

    private function lockedRecord(MaintenanceRecord $record): MaintenanceRecord
    {
        return MaintenanceRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Re-reads the request under a row lock and refuses edits once it is
     * closed, cancelled or commercially billed — the in-memory model may be
     * stale, so the guard must see the committed state.
     */
    private function lockedMutableRecord(MaintenanceRecord $record): MaintenanceRecord
    {
        $locked = $this->lockedRecord($record);

        if ($locked->isLockedForChanges()) {
            throw ValidationException::withMessages([
                'record' => 'Closed, cancelled or already-billed maintenance requests cannot be edited.',
            ]);
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveStandaloneEquipment(array $data): array
    {
        $customerId = $data['customer_id'] ?? null;
        $customer = is_numeric($customerId) ? CustomerProfile::query()->find((int) $customerId) : null;

        if (! $customer instanceof CustomerProfile) {
            throw ValidationException::withMessages([
                'customer_id' => 'A valid customer is required before equipment can be selected.',
            ]);
        }

        $unit = null;
        $selectedUnitId = $data['serialized_inventory_unit_id'] ?? null;
        $serial = $data['serial_number'] ?? null;
        $serial = is_string($serial) ? mb_trim($serial) : null;
        $serial = $serial === '' ? null : $serial;

        if (is_numeric($selectedUnitId)) {
            $unit = SerializedInventoryUnit::query()
                ->with('productVariant')
                ->find((int) $selectedUnitId);

            if (! $unit instanceof SerializedInventoryUnit) {
                throw ValidationException::withMessages([
                    'serialized_inventory_unit_id' => 'The selected equipment could not be found.',
                ]);
            }

            $this->assertUnitBelongsToCustomer($unit, $customer);
            $serial = $unit->serial_number;
        } elseif ($serial !== null) {
            $unit = SerializedInventoryUnit::query()
                ->with('productVariant')
                ->whereRaw('LOWER(serial_number) = ?', [mb_strtolower($serial)])
                ->first();

            if ($unit instanceof SerializedInventoryUnit) {
                $this->assertUnitBelongsToCustomer($unit, $customer);
            }
        }

        $explicitStatus = $this->explicitWarrantyStatus($data);
        $explicitExpiry = $data['warranty_expiry_date'] ?? null;

        if ($explicitStatus === WarrantyStatus::Covered && empty($explicitExpiry)) {
            throw ValidationException::withMessages([
                'warranty_expiry_date' => 'A warranty expiry date is required when warranty is covered.',
            ]);
        }

        $coverage = null;
        if (! $explicitStatus instanceof WarrantyStatus && $unit instanceof SerializedInventoryUnit) {
            $coverage = $this->warrantyResolver->resolveForSerializedUnit($unit, $customer);
        }

        if (! $explicitStatus instanceof WarrantyStatus && ! $coverage instanceof WarrantyCoverage && $serial !== null && ! $unit instanceof SerializedInventoryUnit) {
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

    private function assertUnitBelongsToCustomer(SerializedInventoryUnit $unit, CustomerProfile $customer): void
    {
        if ($unit->custody_type !== SerializedCustodyType::Customer) {
            return;
        }

        if (! is_numeric($unit->custody_reference_id) || (int) $unit->custody_reference_id !== $customer->id) {
            throw ValidationException::withMessages([
                'serialized_inventory_unit_id' => 'The selected equipment is not in this customer custody.',
            ]);
        }
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
