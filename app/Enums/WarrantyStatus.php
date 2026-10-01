<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Warranty coverage snapshot used by support and maintenance records.
 * `Covered` requires a non-null expiry date; `NotApplicable` is used for
 * equipment that was not sold/covered by IERP and therefore has no IERP
 * warranty entitlement.
 */
enum WarrantyStatus: string
{
    case Covered = 'covered';
    case Expired = 'expired';
    case NotCovered = 'not_covered';
    case NotApplicable = 'not_applicable';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Covered => __(__('Active')),
            self::Expired => __(__('Expired')),
            self::NotCovered => __(__('No warranty')),
            self::NotApplicable => __(__('Not applicable')),
            self::Unknown => __(__('Needs verification')),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Covered => 'success',
            self::Expired => 'gray',
            self::NotCovered => 'gray',
            self::NotApplicable => 'gray',
            self::Unknown => 'warning',
        };
    }
}
