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
 * Triage identifies equipment, snapshots warranty eligibility, and chooses the
 * service route. Final repair coverage is deliberately deferred until
 * diagnosis. A payment hold here represents only an explicit diagnostic fee.
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
        $legacyBillingDecision = $this->legacyBillingDecision($data['billing_decision'] ?? null);
        $diagnosticFeeRequired = (bool) ($data['diagnostic_fee_required']
            ?? ($legacyBillingDecision === 'payment_required'));

        return DB::transaction(function () use (
            $ticket,
            $data,
            $actor,
            $equipmentSource,
            $servicePath,
            $legacyBillingDecision,
            $diagnosticFeeRequired,
        ): Ticket {
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

            $waiveReason = null;
            if ($legacyBillingDecision === 'waive') {
                $waiveReason = $this->nullableString($data['charge_waived_reason'] ?? null);

                if ($waiveReason === null) {
                    throw ValidationException::withMessages([
                        'charge_waived_reason' => 'A reason is required when a legacy triage charge is waived.',
                    ]);
                }
            }

            $externalEquipmentName = $equipmentSource === TicketEquipmentSource::External
                ? ($this->nullableString($data['external_equipment_name'] ?? null) ?? '')
                : null;

            if ($equipmentSource === TicketEquipmentSource::External && $externalEquipmentName === '') {
                throw ValidationException::withMessages([
                    'external_equipment_name' => 'Equipment name is required for external equipment.',
                ]);
            }

            $amount = $data['diagnostic_fee_amount'] ?? $data['amount'] ?? null;
            $currency = $data['diagnostic_fee_currency'] ?? $data['currency'] ?? null;

            if ($diagnosticFeeRequired) {
                if (! is_numeric($amount) || (float) $amount <= 0 || ! is_string($currency) || mb_trim($currency) === '') {
                    throw ValidationException::withMessages([
                        'diagnostic_fee_amount' => 'A diagnostic fee requires a positive amount and currency.',
                    ]);
                }
            }

            $nextStatus = $diagnosticFeeRequired ? TicketStatus::PendingPayment : TicketStatus::Live;

            $locked->update([
                'equipment_source' => $equipmentSource,
                'serialized_inventory_unit_id' => $equipment?->getKey(),
                'external_equipment_name' => $externalEquipmentName,
                'external_equipment_model' => $equipmentSource === TicketEquipmentSource::External
                    ? $this->nullableString($data['external_equipment_model'] ?? null)
                    : null,
                'external_serial_number' => $equipmentSource === TicketEquipmentSource::External
                    ? $this->nullableString($data['external_serial_number'] ?? null)
                    : null,
                'warranty_status' => $warranty->status,
                'warranty_expiry_date' => $warranty->expiresOn?->toDateString(),
                'service_path' => $servicePath,
                'triaged_at' => now(),
                'triaged_by' => $actor->getKey(),
                'is_chargeable' => $diagnosticFeeRequired,
                'diagnostic_fee_required' => $diagnosticFeeRequired,
                'diagnostic_fee_amount' => $diagnosticFeeRequired ? (float) $amount : null,
                'diagnostic_fee_currency' => $diagnosticFeeRequired ? mb_strtoupper(mb_trim((string) $currency)) : null,
                'charge_waived_reason' => $waiveReason,
                'status' => $nextStatus,
                'pending_reason' => $diagnosticFeeRequired
                    ? 'Diagnostic fee is awaited before technical work can begin.'
                    : null,
                'updated_by' => $actor->getKey(),
            ]);

            if ($diagnosticFeeRequired) {
                $this->paymentService->createForTicket($locked, (float) $amount, mb_trim((string) $currency));
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
                    'diagnostic_fee_required' => $diagnosticFeeRequired,
                    'status' => $nextStatus->value,
                ]])
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'ip_address' => request()->ip(),
                    'warranty_reason' => $warranty->reason,
                    'legacy_billing_decision' => $legacyBillingDecision,
                ])
                ->log('support.ticket.triaged');

            if ($legacyBillingDecision === 'waive') {
                activity()
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->withProperties(['source_channel' => 'dashboard', 'reason' => $waiveReason])
                    ->log('support.ticket.legacy_charge_waived');
            }

            DB::afterCommit(static fn () => TicketUpdated::dispatch(
                $locked->refresh()->load('customer.user'),
            ));

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    private function resolveEquipment(
        Ticket $ticket,
        TicketEquipmentSource $source,
        array $data,
    ): ?SerializedInventoryUnit {
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
            ->with(['productVariant.warrantyPolicy', 'warrantyEntitlements'])
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
        if ($value instanceof TicketEquipmentSource) {
            return $value;
        }

        $resolved = is_string($value) ? TicketEquipmentSource::tryFrom($value) : null;

        if (! $resolved instanceof TicketEquipmentSource) {
            throw ValidationException::withMessages([
                'equipment_source' => 'Choose a valid equipment source.',
            ]);
        }

        return $resolved;
    }

    private function servicePath(mixed $value): TicketServicePath
    {
        if ($value instanceof TicketServicePath) {
            return $value;
        }

        $resolved = is_string($value) ? TicketServicePath::tryFrom($value) : null;

        if (! $resolved instanceof TicketServicePath) {
            throw ValidationException::withMessages([
                'service_path' => 'Choose a valid service path.',
            ]);
        }

        return $resolved;
    }

    private function legacyBillingDecision(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! in_array($value, ['no_charge', 'payment_required', 'waive'], true)) {
            throw ValidationException::withMessages([
                'billing_decision' => 'Choose a valid legacy billing decision.',
            ]);
        }

        return $value;
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
