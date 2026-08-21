<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;

/**
 * Shared lifecycle vocabulary for {@see MaintenanceRecord} ("Maintenance
 * Request") and {@see MaintenanceTask} ("Service Record") — one enum, one
 * rule, two call sites (FR-065/073,
 * contracts/maintenance-lifecycle.md §1).
 */
enum MaintenanceStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Closed, self::Cancelled],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
