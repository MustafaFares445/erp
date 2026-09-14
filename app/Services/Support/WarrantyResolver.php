<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\WarrantyCoverage;
use App\Enums\SerializedCustodyType;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
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
        $custodyReferenceId = $unit->custody_reference_id;
        $customerId = $customer->getKey();

        if ($unit->custody_type !== SerializedCustodyType::Customer
            || ! is_numeric($custodyReferenceId)
            || ! is_numeric($customerId)
            || (int) $custodyReferenceId !== (int) $customerId) {
            return new WarrantyCoverage(
                WarrantyStatus::Unknown,
                $unit->warranty_started_on,
                $unit->warranty_expires_on,
                $unitId,
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
                    $unitId,
                    'The product variant has no IERP customer warranty configured.',
                );
            }

            return new WarrantyCoverage(
                WarrantyStatus::Unknown,
                null,
                null,
                $unitId,
                'Warranty provenance has not been activated from a confirmed delivery.',
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

        $status = $at->startOfDay()->lte($unit->warranty_expires_on->endOfDay())
            ? WarrantyStatus::Covered
            : WarrantyStatus::Expired;

        return new WarrantyCoverage(
            $status,
            $unit->warranty_started_on,
            $unit->warranty_expires_on,
            $unitId,
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

    private static function integerKey(SerializedInventoryUnit $unit): int
    {
        $key = $unit->getKey();

        if (! is_numeric($key)) {
            throw new LogicException('Serialized inventory units must have numeric identifiers.');
        }

        return (int) $key;
    }
}
