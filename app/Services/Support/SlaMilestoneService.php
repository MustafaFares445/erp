<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\SlaMilestoneKey;
use App\Models\SlaCalendar;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketSlaMilestone;
use Carbon\CarbonInterface;
use DomainException;

final readonly class SlaMilestoneService
{
    public function __construct(private SlaBusinessTimeCalculator $calculator) {}

    public function start(
        Ticket $ticket,
        SlaMilestoneKey $key,
        SlaPolicy $policy,
        int $targetMinutes,
        CarbonInterface $startedAt,
    ): TicketSlaMilestone {
        $existing = $ticket->slaMilestones()->where('key', $key->value)->first();

        if ($existing instanceof TicketSlaMilestone && $existing->started_at !== null) {
            return $existing;
        }

        $calendar = $this->calendarFor($policy);
        $dueAt = $this->calculator->addBusinessMinutes($startedAt, $targetMinutes, $calendar);

        return TicketSlaMilestone::query()->updateOrCreate(
            ['ticket_id' => $ticket->getKey(), 'key' => $key->value],
            [
                'sla_policy_id' => $policy->exists ? $policy->getKey() : null,
                'target_minutes' => $targetMinutes,
                'started_at' => $startedAt,
                'due_at' => $dueAt,
                'paused_at' => null,
                'paused_seconds' => 0,
                'completed_at' => null,
                'breached_at' => null,
            ],
        );
    }

    public function complete(Ticket $ticket, SlaMilestoneKey $key, ?CarbonInterface $at = null): ?TicketSlaMilestone
    {
        $milestone = $ticket->slaMilestones()->where('key', $key->value)->first();

        if (! $milestone instanceof TicketSlaMilestone || $milestone->completed_at !== null) {
            return $milestone;
        }

        $milestone->update(['completed_at' => $at ?? now()]);

        return $milestone->refresh();
    }

    public function pause(Ticket $ticket, SlaMilestoneKey $key, ?CarbonInterface $at = null): ?TicketSlaMilestone
    {
        $milestone = $ticket->slaMilestones()->where('key', $key->value)->first();

        if (! $milestone instanceof TicketSlaMilestone || $milestone->completed_at !== null || $milestone->paused_at !== null) {
            return $milestone;
        }

        $milestone->update(['paused_at' => $at ?? now()]);

        return $milestone->refresh();
    }

    public function resume(Ticket $ticket, SlaMilestoneKey $key, ?CarbonInterface $at = null): ?TicketSlaMilestone
    {
        $milestone = $ticket->slaMilestones()->with('policy.calendar.periods', 'policy.calendar.exceptions')->where('key', $key->value)->first();

        if (! $milestone instanceof TicketSlaMilestone || $milestone->paused_at === null) {
            return $milestone;
        }

        $at ??= now();
        $calendar = $milestone->policy?->calendar;

        if (! $calendar instanceof SlaCalendar) {
            throw new DomainException('The SLA milestone cannot resume without its business calendar.');
        }

        $pausedBusinessMinutes = $this->calculator->businessMinutesBetween($milestone->paused_at, $at, $calendar);
        $pausedWallSeconds = (int) $milestone->paused_at->diffInSeconds($at);
        $dueAt = $milestone->due_at;

        if ($dueAt !== null && $pausedBusinessMinutes > 0) {
            $dueAt = $this->calculator->addBusinessMinutes($dueAt, $pausedBusinessMinutes, $calendar);
        }

        $milestone->update([
            'paused_at' => null,
            'paused_seconds' => $milestone->paused_seconds + $pausedWallSeconds,
            'due_at' => $dueAt,
        ]);

        return $milestone->refresh();
    }

    public function resnapshot(
        Ticket $ticket,
        SlaMilestoneKey $key,
        SlaPolicy $policy,
        int $targetMinutes,
        CarbonInterface $anchor,
        int $pausedSeconds = 0,
    ): TicketSlaMilestone {
        $calendar = $this->calendarFor($policy);
        $dueAt = $this->calculator->addBusinessMinutes($anchor, $targetMinutes, $calendar);

        if ($pausedSeconds > 0) {
            $dueAt = $dueAt->addSeconds($pausedSeconds);
        }

        $milestone = TicketSlaMilestone::query()->updateOrCreate(
            ['ticket_id' => $ticket->getKey(), 'key' => $key->value],
            [
                'sla_policy_id' => $policy->exists ? $policy->getKey() : null,
                'target_minutes' => $targetMinutes,
                'started_at' => $anchor,
                'due_at' => $dueAt,
            ],
        );

        if ($milestone->completed_at === null && $milestone->breached_at === null && now()->gt($dueAt)) {
            $milestone->update(['breached_at' => now()]);
        }

        return $milestone->refresh();
    }

    public function reopen(Ticket $ticket, SlaMilestoneKey $key): ?TicketSlaMilestone
    {
        $milestone = $ticket->slaMilestones()->where('key', $key->value)->first();

        if (! $milestone instanceof TicketSlaMilestone) {
            return null;
        }

        $attributes = ['completed_at' => null];

        if ($milestone->due_at !== null && now()->gt($milestone->due_at) && $milestone->breached_at === null) {
            $attributes['breached_at'] = now();
        }

        $milestone->update($attributes);

        return $milestone->refresh();
    }

    /** @return list<SlaMilestoneKey> */
    public function refreshBreaches(Ticket $ticket): array
    {
        $newlyBreached = [];

        $ticket->slaMilestones()
            ->whereNull('completed_at')
            ->whereNull('breached_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->get()
            ->each(function (TicketSlaMilestone $milestone) use (&$newlyBreached): void {
                $milestone->update(['breached_at' => now()]);
                $newlyBreached[] = $milestone->key;
            });

        return $newlyBreached;
    }

    private function calendarFor(SlaPolicy $policy): SlaCalendar
    {
        $calendar = $policy->calendar;

        if ($calendar instanceof SlaCalendar && $calendar->is_active) {
            return $calendar;
        }

        $default = SlaCalendar::query()->where('is_active', true)->where('is_default', true)->first();

        if ($default instanceof SlaCalendar) {
            return $default;
        }

        throw new DomainException('No active SLA calendar is configured.');
    }
}
