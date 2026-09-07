<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\MaintenanceScheduleOccurrence;

/**
 * Lifecycle of a single {@see MaintenanceScheduleOccurrence} (WP-3.6,
 * GAP-MW-08, MT-07) — the row that makes a missed preventive service visible
 * as a row rather than an absence.
 */
enum OccurrenceStatus: string
{
    case Pending = 'pending';
    case Raised = 'raised';
    case Completed = 'completed';
    case Missed = 'missed';
    case Skipped = 'skipped';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
