<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\ServiceRecordPart;

/**
 * Where a {@see ServiceRecordPart}'s cost snapshot came from (WP-2.9,
 * GAP-MW-09). `Unknown` means no cost was available at consumption time —
 * this is recorded rather than defaulted to zero so job-cost coverage can be
 * reported honestly instead of silently understating the job's true cost.
 */
enum CostSource: string
{
    case LastReceivedCost = 'last_received_cost';
    case ManualEntry = 'manual_entry';
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }
}
