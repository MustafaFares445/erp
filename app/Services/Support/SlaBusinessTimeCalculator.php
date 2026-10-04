<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SlaCalendar;
use App\Models\SlaCalendarException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;

final class SlaBusinessTimeCalculator
{
    public function addBusinessMinutes(CarbonInterface $start, int $minutes, SlaCalendar $calendar): CarbonImmutable
    {
        if ($minutes < 0) {
            throw new DomainException('SLA target minutes cannot be negative.');
        }

        $timezone = $calendar->timezone ?: config()->string('app.timezone', 'UTC');
        $cursor = CarbonImmutable::instance($start)->setTimezone($timezone);

        if ($minutes === 0) {
            return $this->applicationTime($cursor);
        }

        if ($calendar->is_24x7) {
            return $this->applicationTime($cursor->addMinutes($minutes));
        }

        $calendar->loadMissing(['periods', 'exceptions']);
        $remaining = $minutes;

        for ($guard = 0; $guard < 3700; $guard++) {
            $windows = $this->workingWindows($cursor, $calendar);

            foreach ($windows as [$windowStart, $windowEnd]) {
                if ($cursor->gte($windowEnd)) {
                    continue;
                }

                if ($cursor->lt($windowStart)) {
                    $cursor = $windowStart;
                }

                $available = (int) floor($cursor->diffInMinutes($windowEnd));

                if ($available <= 0) {
                    continue;
                }

                if ($remaining <= $available) {
                    return $this->applicationTime($cursor->addMinutes($remaining));
                }

                $remaining -= $available;
                $cursor = $windowEnd;
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        throw new DomainException('Unable to resolve an SLA due time from the configured business calendar.');
    }

    public function businessMinutesBetween(CarbonInterface $from, CarbonInterface $to, SlaCalendar $calendar): int
    {
        if ($to->lte($from)) {
            return 0;
        }

        $timezone = $calendar->timezone ?: config()->string('app.timezone', 'UTC');
        $start = CarbonImmutable::instance($from)->setTimezone($timezone);
        $end = CarbonImmutable::instance($to)->setTimezone($timezone);

        if ($calendar->is_24x7) {
            return (int) floor($start->diffInMinutes($end));
        }

        $calendar->loadMissing(['periods', 'exceptions']);
        $cursor = $start->startOfDay();
        $total = 0;

        for ($guard = 0; $guard < 3700 && $cursor->lt($end); $guard++) {
            foreach ($this->workingWindows($cursor, $calendar) as [$windowStart, $windowEnd]) {
                $overlapStart = $windowStart->max($start);
                $overlapEnd = $windowEnd->min($end);

                if ($overlapEnd->gt($overlapStart)) {
                    $total += (int) floor($overlapStart->diffInMinutes($overlapEnd));
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return $total;
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> */
    private function workingWindows(CarbonImmutable $date, SlaCalendar $calendar): array
    {
        $timezone = $calendar->timezone ?: config()->string('app.timezone', 'UTC');
        $day = $date->setTimezone($timezone)->startOfDay();
        $exception = $calendar->exceptions->first(
            static fn (SlaCalendarException $item): bool => $item->date->toDateString() === $day->toDateString(),
        );

        if ($exception !== null) {
            if (! $exception->is_working_day) {
                return [];
            }

            if ($exception->starts_at !== null && $exception->ends_at !== null) {
                return [[
                    CarbonImmutable::parse($day->toDateString().' '.$exception->starts_at, $timezone),
                    CarbonImmutable::parse($day->toDateString().' '.$exception->ends_at, $timezone),
                ]];
            }
        }

        $windows = [];

        foreach ($calendar->periods->where('weekday', $day->dayOfWeekIso) as $period) {
            $window = [
                CarbonImmutable::parse($day->toDateString().' '.$period->starts_at, $timezone),
                CarbonImmutable::parse($day->toDateString().' '.$period->ends_at, $timezone),
            ];

            if ($window[1]->gt($window[0])) {
                $windows[] = $window;
            }
        }

        return $windows;
    }

    private function applicationTime(CarbonImmutable $date): CarbonImmutable
    {
        return $date->setTimezone(config()->string('app.timezone', 'UTC'));
    }
}
