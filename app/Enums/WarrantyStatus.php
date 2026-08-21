<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\MaintenanceRecord;

/**
 * Warranty coverage on a {@see MaintenanceRecord} (FR-064). `Covered`
 * requires a non-null `warranty_expiry_date`, rejected at the service layer
 * when absent (contracts/maintenance-lifecycle.md §2).
 */
enum WarrantyStatus: string
{
    case Covered = 'covered';
    case Expired = 'expired';
    case Unknown = 'unknown';
}
