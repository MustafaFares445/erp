<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\WarrantyCoverage;
use App\Enums\SerializedCustodyType;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use Carbon\CarbonInterface;

final readonly class WarrantyResolver
{
    public function resolveForSerializedUnit(
        SerializedInventoryUnit $unit,
        CustomerProfile $customer,
        ?CarbonInterface $at = null,
    ): WarrantyCoverage {
        $at ??= now();

        if ($unit->custody_type !== SerializedCustodyType::Customer
            || (int) $unit->custody_reference_id !== (int) $customer->getKey()) {
            return new WarrantyCoverage(
                WarrantyStatus::Unknown,
                $unit->warranty_started_on,
                $unit->warranty_expires_on,
                $unit->getKey(),
                'The serialized unit is not currently recorded in this customer custody.',
            );
        }

        if ($unit->warranty_started_on === null && $unit->warranty_expires_on === null) {
            $variant = $unit->productVariant;

            if ($variant !== null && ($variant->warranty_duration_value === null || $variant->warranty_duration_unit === null)) {
                return new WarrantyCoverage(
                    WarrantyStatus::NotCovered,
                    null,
                    null,
                    $unit->getKey(),
                    'The product variant has no IERP customer warranty configured.',
                );
            }

            return new WarrantyCoverage(
                WarrantyStatus::Unknown,
                null,
                null,
                $unit->getKey(),
                'Warranty provenance has not been activated from a confirmed delivery.',
            );
        }

        if ($unit->warranty_expires_on === null) {
            return new WarrantyCoverage(
                WarrantyStatus::Unknown,
                $unit->warranty_started_on,
                null,
                $unit->getKey(),
                'Warranty start is known but the expiry date is incomplete.',
            );
        }

        $status = $at->startOfDay()->lte($unit->warranty_expires_on->endOfDay())
            ? WarrantyStatus::Covered
            : WarrantyStatus::Expired;

        return new WarrantyCoverage(
            $status,
            $unit->warranty_started_on,
            $unit->warranty_expires_on,
            $unit->getKey(),
            $status === WarrantyStatus::Covered
                ? 'The equipment is inside its IERP customer warranty period.'
                : 'The IERP customer warranty period has expired.',
        );
    }

    public function externalEquipment(): WarrantyCoverage
    {
        return new WarrantyCoverage(
            WarrantyStatus::NotApplicable,
            null,
            null,
            null,
            'External equipment is not covered by an IERP sale warranty.',
        );
    }
}
