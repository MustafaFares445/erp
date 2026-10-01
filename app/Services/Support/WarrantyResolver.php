<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\WarrantyCoverage;
use App\Enums\SerializedCustodyType;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\WarrantyEntitlement;
use Carbon\CarbonInterface;
use LogicException;

final readonly class WarrantyResolver
{
    public function resolveForSerializedUnit(
        SerializedInventoryUnit $unit,
        CustomerProfile $customer,
        ?CarbonInterface $at = null,
    ): WarrantyCoverage {
        $at ??= now();
        $unitId = self::integerKey($unit);
        $customerId = $customer->getKey();

        if (
            $unit->custody_type !== SerializedCustodyType::Customer
            || ! is_numeric($unit->custody_reference_id)
            || ! is_numeric($customerId)
            || (int) $unit->custody_reference_id !== (int) $customerId
        ) {
            return new WarrantyCoverage(
                WarrantyStatus::Unknown,
                null,
                null,
                $unitId,
                'The serialized unit is not currently recorded in this customer custody.',
            );
        }

        $entitlement = WarrantyEntitlement::query()
            ->where('serialized_inventory_unit_id', $unitId)
            ->where('customer_id', (int) $customerId)
            ->latest('id')
            ->first();

        if ($entitlement instanceof WarrantyEntitlement) {
            return $this->fromEntitlement($entitlement, $unitId, $at);
        }

        if ($unit->warranty_started_on === null && $unit->warranty_expires_on === null) {
            $variant = $unit->productVariant;

            if (
                $variant !== null
                && $variant->warrantyPolicy === null
                && ($variant->warranty_duration_value === null || $variant->warranty_duration_unit === null)
            ) {
                return new WarrantyCoverage(
                    WarrantyStatus::NotCovered,
                    null,
                    null,
                    $unitId,
                    'The product variant has no customer warranty policy configured.',
                );
            }

            return new WarrantyCoverage(
                WarrantyStatus::Unknown,
                null,
                null,
                $unitId,
                'Warranty entitlement has not been activated from its configured start trigger.',
            );
        }

        if ($unit->warranty_expires_on === null) {
            return new WarrantyCoverage(
                WarrantyStatus::Unknown,
                $unit->warranty_started_on,
                null,
                $unitId,
                'Warranty start is known but the expiry date is incomplete.',
            );
        }

        $status = $at->copy()->startOfDay()->lte($unit->warranty_expires_on->copy()->endOfDay())
            ? WarrantyStatus::Covered
            : WarrantyStatus::Expired;

        return new WarrantyCoverage(
            $status,
            $unit->warranty_started_on,
            $unit->warranty_expires_on,
            $unitId,
            $status === WarrantyStatus::Covered
                ? 'The equipment has an active customer warranty entitlement.'
                : 'The customer warranty entitlement has expired.',
        );
    }

    public function externalEquipment(): WarrantyCoverage
    {
        return new WarrantyCoverage(
            WarrantyStatus::NotApplicable,
            null,
            null,
            null,
            'External equipment is not eligible for an IERP sale warranty.',
        );
    }

    private function fromEntitlement(
        WarrantyEntitlement $entitlement,
        int $unitId,
        CarbonInterface $at,
    ): WarrantyCoverage {
        if ($entitlement->state === WarrantyEntitlementState::PendingActivation) {
            return new WarrantyCoverage(
                WarrantyStatus::Unknown,
                $entitlement->starts_on,
                $entitlement->expires_on,
                $unitId,
                'Warranty entitlement exists but is waiting for '.$entitlement->start_trigger->label().'.',
            );
        }

        if ($entitlement->starts_on === null || $entitlement->expires_on === null) {
            return new WarrantyCoverage(
                WarrantyStatus::Unknown,
                $entitlement->starts_on,
                $entitlement->expires_on,
                $unitId,
                'Warranty entitlement dates are incomplete.',
            );
        }

        if (in_array($entitlement->state, [WarrantyEntitlementState::Ended, WarrantyEntitlementState::Cancelled], true)) {
            return new WarrantyCoverage(
                WarrantyStatus::Expired,
                $entitlement->starts_on,
                $entitlement->expires_on,
                $unitId,
                $entitlement->end_reason ?: 'The warranty entitlement is no longer active.',
            );
        }

        $covered = $at->copy()->startOfDay()->betweenIncluded(
            $entitlement->starts_on->copy()->startOfDay(),
            $entitlement->expires_on->copy()->endOfDay(),
        );

        return new WarrantyCoverage(
            $covered ? WarrantyStatus::Covered : WarrantyStatus::Expired,
            $entitlement->starts_on,
            $entitlement->expires_on,
            $unitId,
            $covered
                ? 'The equipment has an active customer warranty entitlement.'
                : 'The customer warranty entitlement has expired.',
        );
    }

    private static function integerKey(SerializedInventoryUnit $unit): int
    {
        $key = $unit->getKey();

        if (! is_numeric($key)) {
            throw new LogicException('Serialized inventory units must have numeric identifiers.');
        }

        return (int) $key;
    }
}
