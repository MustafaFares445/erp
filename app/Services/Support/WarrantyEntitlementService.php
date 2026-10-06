<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\InventoryReturnType;
use App\Enums\SerializedCustodyType;
use App\Enums\SupportPermission;
use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyEntitlementState;
use App\Models\InventoryReturn;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class WarrantyEntitlementService
{
    public function activate(
        WarrantyEntitlement $entitlement,
        CarbonInterface $startsOn,
        User $actor,
        string $reason,
    ): WarrantyEntitlement {
        Gate::forUser($actor)->authorize(SupportPermission::WarrantyOverride->value);

        if ($entitlement->state !== WarrantyEntitlementState::PendingActivation) {
            throw new DomainException('Only a pending warranty entitlement can be activated.');
        }

        if (mb_trim($reason) === '') {
            throw new DomainException('An activation reason is required.');
        }

        return DB::transaction(function () use ($entitlement, $startsOn, $actor, $reason): WarrantyEntitlement {
            $locked = WarrantyEntitlement::query()
                ->whereKey($entitlement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->state !== WarrantyEntitlementState::PendingActivation) {
                throw new DomainException('This warranty entitlement has already been processed.');
            }

            $start = Carbon::parse($startsOn)->startOfDay();
            $expiry = $this->expiry($start, $locked->duration_value, $locked->duration_unit);

            $locked->update([
                'state' => WarrantyEntitlementState::Active,
                'starts_on' => $start->toDateString(),
                'expires_on' => $expiry->toDateString(),
                'ended_at' => null,
                'end_reason' => null,
            ]);

            $this->syncUnitSnapshot($locked);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges(['attributes' => [
                    'state' => WarrantyEntitlementState::Active->value,
                    'starts_on' => $start->toDateString(),
                    'expires_on' => $expiry->toDateString(),
                ]])
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'reason' => mb_trim($reason),
                ])
                ->log('support.warranty_entitlement.activated');

            return $locked->refresh();
        });
    }

    public function endForPostedCustomerReturn(InventoryReturn $return, User $actor): int
    {
        if ($return->return_type !== InventoryReturnType::Customer || ! $return->isPosted() || ! is_int($return->customer_id)) {
            return 0;
        }

        $unitIds = $return->lines()
            ->whereNotNull('serialized_inventory_unit_id')
            ->pluck('serialized_inventory_unit_id')
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($unitIds->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($return, $actor, $unitIds): int {
            $entitlements = WarrantyEntitlement::query()
                ->whereIn('serialized_inventory_unit_id', $unitIds->all())
                ->where('customer_id', $return->customer_id)
                ->whereIn('state', [
                    WarrantyEntitlementState::Active->value,
                    WarrantyEntitlementState::PendingActivation->value,
                ])
                ->lockForUpdate()
                ->get();

            $ended = 0;

            foreach ($entitlements as $entitlement) {
                $entitlement->update([
                    'state' => WarrantyEntitlementState::Ended,
                    'ended_at' => now(),
                    'end_reason' => 'Equipment returned by customer on return '.$return->return_number.'.',
                ]);

                $unit = $entitlement->serializedInventoryUnit;
                if ($unit instanceof SerializedInventoryUnit) {
                    $unit->forceFill([
                        'warranty_started_on' => null,
                        'warranty_expires_on' => null,
                        'warranty_source_shipment_id' => null,
                    ])->save();
                }

                activity()
                    ->performedOn($entitlement)
                    ->causedBy($actor)
                    ->withChanges(['attributes' => [
                        'state' => WarrantyEntitlementState::Ended->value,
                        'ended_at' => now()->toDateTimeString(),
                    ]])
                    ->withProperties([
                        'source_channel' => 'inventory_return',
                        'inventory_return_id' => $return->getKey(),
                        'return_number' => $return->return_number,
                    ])
                    ->log('support.warranty_entitlement.ended_by_customer_return');

                $ended++;
            }

            return $ended;
        });
    }

    public function applyReplacement(
        WarrantyEntitlement $original,
        SerializedInventoryUnit $replacementUnit,
        User $actor,
        string $reason,
        ?CarbonInterface $manualExpiry = null,
    ): WarrantyEntitlement {
        Gate::forUser($actor)->authorize(SupportPermission::WarrantyOverride->value);

        if ($original->state !== WarrantyEntitlementState::Active || $original->expires_on === null) {
            throw ValidationException::withMessages([
                'replacement' => 'Only an active warranty entitlement can be carried to replacement equipment.',
            ]);
        }

        if (mb_trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A replacement reason is required.']);
        }

        if (
            $replacementUnit->custody_type !== SerializedCustodyType::Customer
            || (int) $replacementUnit->custody_reference_id !== (int) $original->customer_id
        ) {
            throw ValidationException::withMessages([
                'replacement_unit_id' => 'The replacement serial must already be in the same customer custody.',
            ]);
        }

        $replacementUnitId = $replacementUnit->getKey();
        if (! is_numeric($replacementUnitId)) {
            throw ValidationException::withMessages([
                'replacement_unit_id' => 'The replacement serial has an invalid identifier.',
            ]);
        }

        if ((int) $replacementUnitId === (int) $original->serialized_inventory_unit_id) {
            throw ValidationException::withMessages([
                'replacement_unit_id' => 'Choose a different serialized unit as the replacement.',
            ]);
        }

        $startsOn = today()->startOfDay();
        $expiresOn = match ($original->replacement_rule) {
            'remaining_original_term' => $original->expires_on->copy()->startOfDay(),
            'restart_full_term' => $this->expiry($startsOn->copy(), $original->duration_value, $original->duration_unit),
            'manual_review' => $manualExpiry instanceof CarbonInterface ? Carbon::parse($manualExpiry)->startOfDay() : null,
            default => throw ValidationException::withMessages(['replacement' => 'Unknown replacement warranty rule.']),
        };

        if (! $expiresOn instanceof Carbon || $expiresOn->lt($startsOn)) {
            throw ValidationException::withMessages([
                'replacement_expiry' => 'The replacement warranty expiry must be today or later.',
            ]);
        }

        return DB::transaction(function () use ($original, $replacementUnit, $actor, $reason, $startsOn, $expiresOn): WarrantyEntitlement {
            $lockedOriginal = WarrantyEntitlement::query()
                ->whereKey($original->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOriginal->state !== WarrantyEntitlementState::Active) {
                throw ValidationException::withMessages([
                    'replacement' => 'Only an active warranty entitlement can be carried to replacement equipment.',
                ]);
            }

            WarrantyEntitlement::query()
                ->where('serialized_inventory_unit_id', $replacementUnit->getKey())
                ->whereIn('state', [
                    WarrantyEntitlementState::Active->value,
                    WarrantyEntitlementState::PendingActivation->value,
                ])
                ->update([
                    'state' => WarrantyEntitlementState::Ended->value,
                    'ended_at' => now(),
                    'end_reason' => 'Superseded by replacement warranty entitlement.',
                ]);

            $replacement = WarrantyEntitlement::query()->create([
                'serialized_inventory_unit_id' => $replacementUnit->getKey(),
                'customer_id' => $lockedOriginal->customer_id,
                'warranty_policy_id' => $lockedOriginal->warranty_policy_id,
                'source_shipment_id' => $replacementUnit->warranty_source_shipment_id,
                'replacement_of_entitlement_id' => $lockedOriginal->getKey(),
                'state' => WarrantyEntitlementState::Active,
                'policy_name' => $lockedOriginal->policy_name,
                'duration_value' => $lockedOriginal->duration_value,
                'duration_unit' => $lockedOriginal->duration_unit,
                'start_trigger' => $lockedOriginal->start_trigger,
                'covers_parts' => $lockedOriginal->covers_parts,
                'covers_labour' => $lockedOriginal->covers_labour,
                'covers_travel' => $lockedOriginal->covers_travel,
                'covers_consumables' => $lockedOriginal->covers_consumables,
                'covers_third_party' => $lockedOriginal->covers_third_party,
                'transferable' => $lockedOriginal->transferable,
                'replacement_rule' => $lockedOriginal->replacement_rule,
                'starts_on' => $startsOn->toDateString(),
                'expires_on' => $expiresOn->toDateString(),
            ]);

            $lockedOriginal->update([
                'state' => WarrantyEntitlementState::Ended,
                'ended_at' => now(),
                'end_reason' => 'Equipment replaced by serial '.$replacementUnit->serial_number.'. '.mb_trim($reason),
            ]);

            $originalUnit = $lockedOriginal->serializedInventoryUnit;
            if ($originalUnit instanceof SerializedInventoryUnit) {
                $originalUnit->forceFill([
                    'warranty_started_on' => null,
                    'warranty_expires_on' => null,
                    'warranty_source_shipment_id' => null,
                ])->save();
            }

            $replacementUnit->forceFill([
                'warranty_started_on' => $startsOn->toDateString(),
                'warranty_expires_on' => $expiresOn->toDateString(),
                'warranty_source_shipment_id' => $replacement->source_shipment_id,
            ])->save();

            activity()
                ->performedOn($replacement)
                ->causedBy($actor)
                ->withChanges(['attributes' => [
                    'replacement_of_entitlement_id' => $lockedOriginal->getKey(),
                    'starts_on' => $startsOn->toDateString(),
                    'expires_on' => $expiresOn->toDateString(),
                ]])
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'reason' => mb_trim($reason),
                    'replacement_serial' => $replacementUnit->serial_number,
                ])
                ->log('support.warranty_entitlement.replaced');

            return $replacement->refresh();
        });
    }

    public function correctDates(
        WarrantyEntitlement $entitlement,
        CarbonInterface $startsOn,
        CarbonInterface $expiresOn,
        User $actor,
        string $reason,
    ): WarrantyEntitlement {
        Gate::forUser($actor)->authorize(SupportPermission::WarrantyOverride->value);

        if ($entitlement->state !== WarrantyEntitlementState::Active) {
            throw new DomainException('Only an active warranty entitlement can have its dates corrected.');
        }

        if (mb_trim($reason) === '') {
            throw new DomainException('A warranty correction reason is required.');
        }

        $start = Carbon::parse($startsOn)->startOfDay();
        $expiry = Carbon::parse($expiresOn)->startOfDay();

        if ($expiry->lt($start)) {
            throw ValidationException::withMessages([
                'expires_on' => 'Warranty expiry cannot be before the warranty start date.',
            ]);
        }

        return DB::transaction(function () use ($entitlement, $start, $expiry, $actor, $reason): WarrantyEntitlement {
            $locked = WarrantyEntitlement::query()
                ->whereKey($entitlement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->state !== WarrantyEntitlementState::Active) {
                throw new DomainException('Only an active warranty entitlement can have its dates corrected.');
            }

            $before = [
                'starts_on' => $locked->starts_on?->toDateString(),
                'expires_on' => $locked->expires_on?->toDateString(),
            ];

            $locked->update([
                'starts_on' => $start->toDateString(),
                'expires_on' => $expiry->toDateString(),
            ]);

            $this->syncUnitSnapshot($locked);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $before,
                    'attributes' => [
                        'starts_on' => $start->toDateString(),
                        'expires_on' => $expiry->toDateString(),
                    ],
                ])
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'reason' => mb_trim($reason),
                ])
                ->log('support.warranty_entitlement.dates_corrected');

            return $locked->refresh();
        });
    }

    public function cancel(WarrantyEntitlement $entitlement, User $actor, string $reason): WarrantyEntitlement
    {
        Gate::forUser($actor)->authorize(SupportPermission::WarrantyOverride->value);

        if (mb_trim($reason) === '') {
            throw new DomainException('A cancellation reason is required.');
        }

        return DB::transaction(function () use ($entitlement, $actor, $reason): WarrantyEntitlement {
            $locked = WarrantyEntitlement::query()
                ->whereKey($entitlement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->state, [WarrantyEntitlementState::Ended, WarrantyEntitlementState::Cancelled], true)) {
                throw new DomainException('This warranty entitlement is already inactive.');
            }

            $locked->update([
                'state' => WarrantyEntitlementState::Cancelled,
                'ended_at' => now(),
                'end_reason' => mb_trim($reason),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['state' => WarrantyEntitlementState::Cancelled->value]])
                ->withProperties(['source_channel' => 'dashboard', 'reason' => mb_trim($reason)])
                ->log('support.warranty_entitlement.cancelled');

            return $locked->refresh();
        });
    }

    private function syncUnitSnapshot(WarrantyEntitlement $entitlement): void
    {
        $unit = $entitlement->serializedInventoryUnit;

        if (
            ! $unit instanceof SerializedInventoryUnit
            || $unit->custody_type !== SerializedCustodyType::Customer
            || (int) $unit->custody_reference_id !== (int) $entitlement->customer_id
        ) {
            return;
        }

        $unit->forceFill([
            'warranty_started_on' => $entitlement->starts_on?->toDateString(),
            'warranty_expires_on' => $entitlement->expires_on?->toDateString(),
            'warranty_source_shipment_id' => $entitlement->source_shipment_id,
        ])->save();
    }

    private function expiry(Carbon $start, int $value, WarrantyDurationUnit $unit): Carbon
    {
        return match ($unit) {
            WarrantyDurationUnit::Days => $start->copy()->addDays($value),
            WarrantyDurationUnit::Months => $start->copy()->addMonthsNoOverflow($value),
            WarrantyDurationUnit::Years => $start->copy()->addYearsNoOverflow($value),
        };
    }
}
