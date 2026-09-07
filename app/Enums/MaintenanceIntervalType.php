<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\MaintenanceSchedule;
use Carbon\Carbon;
use DomainException;

/**
 * Recurrence unit for a {@see MaintenanceSchedule} (WP-3.6, GAP-MW-08,
 * MT-07). Every calendar-based case advances a date via {@see self::advance()};
 * `UsageHours` has no calendar arithmetic — a schedule tied to equipment
 * runtime hours cannot know its next due date from a date alone, so
 * {@see self::advance()} throws rather than silently producing a wrong date.
 * Advancing a `UsageHours` schedule's `next_due_on` is a manual/external
 * operation (e.g. an hour-meter reading fed in from elsewhere), out of scope
 * for this work package.
 */
enum MaintenanceIntervalType: string
{
    case Days = 'days';
    case Weeks = 'weeks';
    case Months = 'months';
    case Years = 'years';
    case UsageHours = 'usage_hours';

    /**
     * Advances `$date` by `$intervalValue` units of this interval.
     *
     * `Months`/`Years` use Carbon's "no overflow" arithmetic (clamped to the
     * last day of the target month) rather than native overflow: 31 January
     * plus one month lands on 28/29 February, never 2/3 March. A maintenance
     * due date silently sliding into the next calendar month would move a
     * job's compliance window without anyone deciding that — clamping is the
     * safer default for a preventive-service due date.
     *
     * @throws DomainException when this case is {@see self::UsageHours}
     */
    public function advance(Carbon $date, int $intervalValue): Carbon
    {
        return match ($this) {
            self::Days => $date->copy()->addDays($intervalValue),
            self::Weeks => $date->copy()->addWeeks($intervalValue),
            self::Months => $date->copy()->addMonthsNoOverflow($intervalValue),
            self::Years => $date->copy()->addYearsNoOverflow($intervalValue),
            self::UsageHours => throw new DomainException(
                'UsageHours schedules have no calendar-based next-due date — advance next_due_on manually from usage telemetry.',
            ),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
