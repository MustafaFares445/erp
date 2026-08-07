<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\CustomerVisit;
use Illuminate\Support\Carbon;

/**
 * Lifecycle status of a {@see CustomerVisit} (data-model.md §5,
 * contracts/plan-lifecycle.md). Self-transitions are rejected everywhere;
 * `Completed` is terminal. `InProgress -> Completed` additionally requires a
 * `checked_out_at` timestamp, which is why {@see self::canTransitionTo()}
 * accepts it as an optional second argument instead of only comparing the
 * two statuses.
 */
enum VisitStatus: string
{
    case Planned = 'Planned';
    case InProgress = 'InProgress';
    case Completed = 'Completed';
    case Missed = 'Missed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planned => [self::InProgress, self::Missed],
            self::InProgress => [self::Completed, self::Missed],
            self::Completed => [],
            self::Missed => [self::Planned],
        };
    }

    public function canTransitionTo(self $target, ?Carbon $checkedOutAt = null): bool
    {
        if (! in_array($target, $this->allowedTransitions(), true)) {
            return false;
        }

        return $target !== self::Completed || $checkedOutAt instanceof Carbon;
    }
}
