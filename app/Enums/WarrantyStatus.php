<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\MaintenanceRecord;

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
}
