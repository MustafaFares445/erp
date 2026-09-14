<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\SerializedCustodyType;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Completes ticket triage atomically: equipment identification, warranty
 * snapshot, service path, and commercial decision. This is the only normal
 * path from a new Pending ticket to Live or PendingPayment.
 */
final readonly class TicketTriageService
{
    public function __construct(
        private WarrantyResolver $warrantyResolver,
        private TicketPaymentService $paymentService,
        private SlaService $slaService,
    ) {}

    /** @param array<string, mixed> $data */
    public function triage(Ticket $ticket, array $data, User $actor): Ticket
    {
        Gate::forUser($actor)->authorize('update', $ticket);

        $equipmentSource = $this->equipmentSource($data['equipment_source'] ?? null);
        $servicePath = $this->servicePath($data['service_path'] ?? null);
        $billingDecision = $this->billingDecision($data['billing_decision'] ?? null);

        return DB::transaction(function () use ($ticket, $data, $actor, $equipmentSource, $servicePath, $billingDecision): Ticket {
            $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TicketStatus::Pending || $locked->triaged_at !== null) {
                throw new DomainException('Only a new pending ticket can be triaged.');
            }

            $equipment = $this->resolveEquipment($locked, $equipmentSource, $data);
            $customer = $locked->customer;

            if (! $customer instanceof CustomerProfile) {
                throw new DomainException('Ticket triage requires a customer profile.');
            }

            $warranty = $equipment instanceof SerializedInventoryUnit
                ? $this->warrantyResolver->resolveForSerializedUnit($equipment, $customer)
                : $this->warrantyResolver->externalEquipment();

            $isChargeable = $billingDecision === 'payment_required';
            $isWaived = $billingDecision === 'waive';
            $waiveReason = $isWaived ? ($this->nullableString($data['charge_waived_reason'] ?? null) ?? '') : null;
            $externalEquipmentName = $equipmentSource === TicketEquipmentSource::External
                ? ($this->nullableString($data['external_equipment_name'] ?? null) ?? '')
                : null;

            if ($isWaived && $waiveReason === '') {
                throw ValidationException::withMessages([
                    'charge_waived_reason' => 'A reason is required when the service charge is waived.',
                ]);
            }

            $nextStatus = $isChargeable ? TicketStatus::PendingPayment : TicketStatus::Live;
            $attributes = [
                'equipment_source' => $equipmentSource,
                'serialized_inventory_unit_id' => $equipment?->getKey(),
                'external_equipment_name' => $externalEquipmentName,
                'external_equipment_model' => $equipmentSource === TicketEquipmentSource::External ? $this->nullableString($data['external_equipment_model'] ?? null) : null,
                'external_serial_number' => $equipmentSource === TicketEquipmentSource::External ? $this->nullableString($data['external_serial_number'] ?? null) : null,
                'warranty_status' => $warranty->status,
                'warranty_expiry_date' => $warranty->expiresOn?->toDateString(),
                'service_path' => $servicePath,
                'triaged_at' => now(),
                'triaged_by' => $actor->getKey(),
                'is_chargeable' => $isChargeable,
                'charge_waived_reason' => $waiveReason,
                'status' => $nextStatus,
                'pending_reason' => $isChargeable ? 'Payment is awaited before this ticket can be worked.' : null,
                'updated_by' => $actor->getKey(),
            ];

            if ($equipmentSource === TicketEquipmentSource::External && $attributes['external_equipment_name'] === '') {
                throw ValidationException::withMessages([
                    'external_equipment_name' => 'Equipment name is required for external equipment.',
                ]);
            }

            $locked->update($attributes);

            if ($isChargeable) {
                $amount = $data['amount'] ?? null;
                $currency = $data['currency'] ?? null;

                if (! is_numeric($amount) || (float) $amount <= 0 || ! is_string($currency) || mb_trim($currency) === '') {
                    throw ValidationException::withMessages([
                        'amount' => 'Payment-required triage needs a positive amount and currency.',
                    ]);
                }

                $this->paymentService->createForTicket($locked, (float) $amount, mb_trim($currency));
            } else {
                $this->slaService->onTicketLive($locked);
            }

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges(['attributes' => [
                    'equipment_source' => $equipmentSource->value,
                    'serialized_inventory_unit_id' => $equipment?->getKey(),
                    'warranty_status' => $warranty->status->value,
                    'service_path' => $servicePath->value,
                    'billing_decision' => $billingDecision,
                    'status' => $nextStatus->value,
                ]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip(), 'warranty_reason' => $warranty->reason])
                ->log('support.ticket.triaged');

            if ($isWaived) {
                activity()
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->withProperties(['source_channel' => 'dashboard', 'reason' => $waiveReason])
                    ->log('support.ticket.charge_waived');
            }

            DB::afterCommit(static fn () => TicketUpdated::dispatch($locked->refresh()->load('customer.user')));

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    private function resolveEquipment(Ticket $ticket, TicketEquipmentSource $source, array $data): ?SerializedInventoryUnit
    {
        if ($source === TicketEquipmentSource::External) {
            return null;
        }

        $unitId = $data['serialized_inventory_unit_id'] ?? null;

        if (! is_numeric($unitId)) {
            throw ValidationException::withMessages([
                'serialized_inventory_unit_id' => 'Select equipment sold to this customer.',
            ]);
        }

        $unit = SerializedInventoryUnit::query()
            ->with('productVariant')
            ->whereKey((int) $unitId)
            ->where('custody_type', SerializedCustodyType::Customer->value)
            ->where('custody_reference_id', $ticket->customer_id)
            ->first();

        if (! $unit instanceof SerializedInventoryUnit) {
            throw ValidationException::withMessages([
                'serialized_inventory_unit_id' => 'The selected equipment is not in this customer custody.',
            ]);
        }

        return $unit;
    }

    private function equipmentSource(mixed $value): TicketEquipmentSource
    {
        try {
            if (! is_string($value) && ! $value instanceof TicketEquipmentSource) {
                throw new \ValueError;
            }

            return $value instanceof TicketEquipmentSource ? $value : TicketEquipmentSource::from($value);
        } catch (\ValueError) {
            throw ValidationException::withMessages(['equipment_source' => 'Choose a valid equipment source.']);
        }
    }

    private function servicePath(mixed $value): TicketServicePath
    {
        try {
            if (! is_string($value) && ! $value instanceof TicketServicePath) {
                throw new \ValueError;
            }

            return $value instanceof TicketServicePath ? $value : TicketServicePath::from($value);
        } catch (\ValueError) {
            throw ValidationException::withMessages(['service_path' => 'Choose a valid service path.']);
        }
    }

    private function billingDecision(mixed $value): string
    {
        $decision = is_string($value) ? $value : '';

        if (! in_array($decision, ['no_charge', 'payment_required', 'waive'], true)) {
            throw ValidationException::withMessages(['billing_decision' => 'Choose a valid billing decision.']);
        }

        return $decision;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }
}
