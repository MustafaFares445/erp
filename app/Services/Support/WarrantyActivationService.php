<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyStartTrigger;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\WarrantyEntitlement;
use App\Models\WarrantyPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Creates an immutable warranty entitlement snapshot from a confirmed customer
 * delivery. Serialized-unit warranty dates remain as a compatibility cache;
 * entitlement history is the source of truth and survives returns/resale.
 */
final readonly class WarrantyActivationService
{
    public function activateForShipment(Shipment $shipment): int
    {
        if (! $shipment->isArrived() || $shipment->confirmed_at === null) {
            return 0;
        }

        $delivery = $shipment->delivery;

        if (! $delivery instanceof InventoryOperation || $delivery->customer_id === null) {
            return 0;
        }

        $unitIds = $delivery->movements()
            ->whereNotNull('serialized_inventory_unit_id')
            ->distinct()
            ->pluck('serialized_inventory_unit_id')
            ->map(static function (mixed $id): int {
                if (! is_numeric($id)) {
                    throw new LogicException('A serialized inventory unit identifier must be numeric.');
                }

                return (int) $id;
            })
            ->all();

        if ($unitIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($unitIds, $shipment, $delivery): int {
            $activated = 0;
            $units = SerializedInventoryUnit::query()
                ->whereKey($unitIds)
                ->with('productVariant.warrantyPolicy')
                ->lockForUpdate()
                ->get();

            foreach ($units as $unit) {
                $variant = $unit->productVariant;

                if (! $variant instanceof ProductVariant) {
                    continue;
                }

                $transferableEntitlement = $this->transferableEntitlementFor(
                    $unit,
                    (int) $delivery->customer_id,
                    Carbon::parse($shipment->confirmed_at),
                );
                $terms = $transferableEntitlement instanceof WarrantyEntitlement
                    ? $this->termsFromEntitlement($transferableEntitlement)
                    : $this->termsFor($variant);

                if ($terms === null) {
                    continue;
                }

                $existing = WarrantyEntitlement::query()
                    ->where('serialized_inventory_unit_id', $unit->getKey())
                    ->where('customer_id', $delivery->customer_id)
                    ->where('source_shipment_id', $shipment->getKey())
                    ->first();

                if ($existing instanceof WarrantyEntitlement) {
                    continue;
                }

                WarrantyEntitlement::query()
                    ->where('serialized_inventory_unit_id', $unit->getKey())
                    ->where('state', WarrantyEntitlementState::Active->value)
                    ->where('customer_id', '!=', $delivery->customer_id)
                    ->update([
                        'state' => WarrantyEntitlementState::Ended->value,
                        'ended_at' => now(),
                        'end_reason' => 'Equipment delivered to another customer.',
                    ]);

                $startsOn = null;
                $expiresOn = null;
                $state = WarrantyEntitlementState::PendingActivation;

                if ($transferableEntitlement instanceof WarrantyEntitlement) {
                    $startsOn = $transferableEntitlement->starts_on?->copy()->startOfDay();
                    $expiresOn = $transferableEntitlement->expires_on?->copy()->startOfDay();
                    $state = WarrantyEntitlementState::Active;
                } elseif ($terms['start_trigger'] === WarrantyStartTrigger::ConfirmedDelivery) {
                    $startsOn = Carbon::parse($shipment->confirmed_at)->startOfDay();
                    $expiresOn = $this->expiry($startsOn, $terms['duration_value'], $terms['duration_unit']);
                    $state = WarrantyEntitlementState::Active;
                }

                WarrantyEntitlement::query()->create([
                    'serialized_inventory_unit_id' => $unit->getKey(),
                    'customer_id' => $delivery->customer_id,
                    'warranty_policy_id' => $terms['policy_id'],
                    'source_shipment_id' => $shipment->getKey(),
                    'state' => $state,
                    'policy_name' => $terms['policy_name'],
                    'duration_value' => $terms['duration_value'],
                    'duration_unit' => $terms['duration_unit'],
                    'start_trigger' => $terms['start_trigger'],
                    'covers_parts' => $terms['covers_parts'],
                    'covers_labour' => $terms['covers_labour'],
                    'covers_travel' => $terms['covers_travel'],
                    'covers_consumables' => $terms['covers_consumables'],
                    'covers_third_party' => $terms['covers_third_party'],
                    'transferable' => $terms['transferable'],
                    'replacement_rule' => $terms['replacement_rule'],
                    'starts_on' => $startsOn?->toDateString(),
                    'expires_on' => $expiresOn?->toDateString(),
                ]);

                if ($state === WarrantyEntitlementState::Active) {
                    $unit->forceFill([
                        'warranty_started_on' => $startsOn?->toDateString(),
                        'warranty_expires_on' => $expiresOn?->toDateString(),
                        'warranty_source_shipment_id' => $shipment->getKey(),
                    ])->save();
                }

                activity()
                    ->performedOn($unit)
                    ->withChanges(['attributes' => [
                        'warranty_started_on' => $startsOn?->toDateString(),
                        'warranty_expires_on' => $expiresOn?->toDateString(),
                        'warranty_source_shipment_id' => $shipment->getKey(),
                        'warranty_entitlement_state' => $state->value,
                    ]])
                    ->withProperties([
                        'source_channel' => 'system',
                        'shipment_id' => $shipment->getKey(),
                        'customer_id' => $delivery->customer_id,
                        'transferred_from_entitlement_id' => $transferableEntitlement?->getKey(),
                    ])
                    ->log('support.warranty.activated');

                $activated++;
            }

            return $activated;
        });
    }

    private function transferableEntitlementFor(
        SerializedInventoryUnit $unit,
        int $newCustomerId,
        Carbon $deliveredAt,
    ): ?WarrantyEntitlement {
        $entitlement = WarrantyEntitlement::query()
            ->where('serialized_inventory_unit_id', $unit->getKey())
            ->where('customer_id', '!=', $newCustomerId)
            ->where('transferable', true)
            ->whereNotNull('starts_on')
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', $deliveredAt->toDateString())
            ->latest('id')
            ->first();

        return $entitlement instanceof WarrantyEntitlement ? $entitlement : null;
    }

    /**
     * @return array{
     *   policy_id:int|null, policy_name:string, duration_value:int,
     *   duration_unit:WarrantyDurationUnit, start_trigger:WarrantyStartTrigger,
     *   covers_parts:bool,covers_labour:bool,covers_travel:bool,
     *   covers_consumables:bool,covers_third_party:bool,transferable:bool,
     *   replacement_rule:string
     * }
     */
    private function termsFromEntitlement(WarrantyEntitlement $entitlement): array
    {
        return [
            'policy_id' => $entitlement->warranty_policy_id,
            'policy_name' => $entitlement->policy_name,
            'duration_value' => $entitlement->duration_value,
            'duration_unit' => $entitlement->duration_unit,
            'start_trigger' => $entitlement->start_trigger,
            'covers_parts' => $entitlement->covers_parts,
            'covers_labour' => $entitlement->covers_labour,
            'covers_travel' => $entitlement->covers_travel,
            'covers_consumables' => $entitlement->covers_consumables,
            'covers_third_party' => $entitlement->covers_third_party,
            'transferable' => $entitlement->transferable,
            'replacement_rule' => $entitlement->replacement_rule,
        ];
    }

    /**
     * @return array{
     *   policy_id:int|null, policy_name:string, duration_value:int,
     *   duration_unit:WarrantyDurationUnit, start_trigger:WarrantyStartTrigger,
     *   covers_parts:bool,covers_labour:bool,covers_travel:bool,
     *   covers_consumables:bool,covers_third_party:bool,transferable:bool,
     *   replacement_rule:string
     * }|null
     */
    private function termsFor(ProductVariant $variant): ?array
    {
        $policy = $variant->warrantyPolicy;

        if ($policy instanceof WarrantyPolicy && $policy->is_active) {
            $policyId = $policy->getKey();
            if (! is_numeric($policyId)) {
                throw new LogicException('Warranty policies must use numeric identifiers.');
            }

            return [
                'policy_id' => (int) $policyId,
                'policy_name' => $policy->name,
                'duration_value' => $policy->duration_value,
                'duration_unit' => $policy->duration_unit,
                'start_trigger' => $policy->start_trigger,
                'covers_parts' => $policy->covers_parts,
                'covers_labour' => $policy->covers_labour,
                'covers_travel' => $policy->covers_travel,
                'covers_consumables' => $policy->covers_consumables,
                'covers_third_party' => $policy->covers_third_party,
                'transferable' => $policy->transferable,
                'replacement_rule' => $policy->replacement_rule,
            ];
        }

        $value = $variant->warranty_duration_value;
        $unit = $variant->warranty_duration_unit;

        if (! is_int($value) || $value <= 0 || ! $unit instanceof WarrantyDurationUnit) {
            return null;
        }

        return [
            'policy_id' => null,
            'policy_name' => 'Legacy customer warranty',
            'duration_value' => $value,
            'duration_unit' => $unit,
            'start_trigger' => WarrantyStartTrigger::ConfirmedDelivery,
            'covers_parts' => true,
            'covers_labour' => true,
            'covers_travel' => false,
            'covers_consumables' => false,
            'covers_third_party' => false,
            'transferable' => false,
            'replacement_rule' => 'remaining_original_term',
        ];
    }

    private function expiry(Carbon $startsOn, int $value, WarrantyDurationUnit $unit): Carbon
    {
        return match ($unit) {
            WarrantyDurationUnit::Days => $startsOn->copy()->addDays($value),
            WarrantyDurationUnit::Months => $startsOn->copy()->addMonthsNoOverflow($value),
            WarrantyDurationUnit::Years => $startsOn->copy()->addYearsNoOverflow($value),
        };
    }
}
