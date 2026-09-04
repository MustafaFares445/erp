<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Console\Commands\ReconcileSlaBreachesCommand;
use App\Enums\TicketPriority;
use App\Events\SlaAtRisk;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;

/**
 * SLA clock start/pause/resume/recompute and breach-flag computation
 * (FR-050–058, contracts/ticket-lifecycle.md §6). Pure timestamp arithmetic
 * in continuous calendar time — no business-hours calendar exists anywhere
 * in this class (clarification, 2026-08-13). `sla_policies` rows are read
 * only here, at clock-start and priority-change, never joined live, so a
 * later policy edit never changes an already-started ticket's due times
 * (SC-006).
 */
final readonly class SlaService
{
    /**
     * Snapshots the current priority's targets and starts the clock the
     * first time a ticket reaches `live` (FR-052/053). Idempotent — a
     * ticket whose clock already started (`live_at` set) is left untouched,
     * so re-entering `live` later (e.g. via unassignment) never resets it.
     */
    public function onTicketLive(Ticket $ticket): void
    {
        if ($ticket->live_at !== null) {
            return;
        }

        $policy = $this->policyFor($ticket->priority);
        $liveAt = now();

        $ticket->update([
            'sla_response_target_minutes' => $policy->response_target_minutes,
            'sla_resolution_target_minutes' => $policy->resolution_target_minutes,
            'live_at' => $liveAt,
            'response_due_at' => $liveAt->clone()->addMinutes($policy->response_target_minutes),
            'resolution_due_at' => $liveAt->clone()->addMinutes($policy->resolution_target_minutes),
        ]);
    }

    /**
     * Suspends the resolution clock (FR-055).
     */
    public function onWaitingCustomer(Ticket $ticket): void
    {
        $ticket->update(['waiting_customer_since' => now()]);
    }

    /**
     * Extends `resolution_due_at` by exactly the paused duration rather
     * than consuming it (FR-055) — the due time moves out, it is not
     * merely "not counted down".
     */
    public function onResumeFromWaiting(Ticket $ticket): void
    {
        if ($ticket->waiting_customer_since === null) {
            return;
        }

        $elapsedSeconds = (int) $ticket->waiting_customer_since->diffInSeconds(now());
        $resolutionDueAt = $ticket->resolution_due_at;

        $ticket->update([
            'waiting_customer_accumulated_seconds' => $ticket->waiting_customer_accumulated_seconds + $elapsedSeconds,
            'resolution_due_at' => $resolutionDueAt?->clone()->addSeconds($elapsedSeconds),
            'waiting_customer_since' => null,
        ]);
    }

    /**
     * Re-snapshots the new priority's targets and recomputes both due
     * timestamps from the original `live_at` — never from now (FR-056).
     * Flags an immediate breach when the recomputation is already past
     * due; never clears an already-set flag (FR-057).
     *
     * The resolution due time is recomputed as `live_at + target +
     * waiting_customer_accumulated_seconds`, preserving any extension
     * already granted by a completed `waiting_customer` pause (FR-055) —
     * recomputing it as a bare `live_at + target` would silently discard
     * that extension. A pause still in progress at the moment of this
     * change is handled correctly by {@see onResumeFromWaiting()}, which
     * always extends whatever `resolution_due_at` currently holds by the
     * full elapsed pause duration when the ticket resumes.
     */
    public function onPriorityChanged(Ticket $ticket, TicketPriority $newPriority, User $actor): void
    {
        if ($ticket->live_at === null) {
            return;
        }

        $policy = $this->policyFor($newPriority);
        $liveAt = $ticket->live_at;
        $responseDueAt = $liveAt->clone()->addMinutes($policy->response_target_minutes);
        $resolutionDueAt = $liveAt->clone()
            ->addMinutes($policy->resolution_target_minutes)
            ->addSeconds($ticket->waiting_customer_accumulated_seconds);
        $now = now();

        $attributes = [
            'sla_response_target_minutes' => $policy->response_target_minutes,
            'sla_resolution_target_minutes' => $policy->resolution_target_minutes,
            'response_due_at' => $responseDueAt,
            'resolution_due_at' => $resolutionDueAt,
        ];

        if (! $ticket->response_breached && $ticket->first_response_at === null && $now->gt($responseDueAt)) {
            $attributes['response_breached'] = true;
        }

        if (! $ticket->resolution_breached && $ticket->resolved_at === null && $now->gt($resolutionDueAt)) {
            $attributes['resolution_breached'] = true;
        }

        $oldValues = $ticket->only(['response_due_at', 'resolution_due_at']);
        $ticket->update($attributes);

        $breachedKinds = [];
        if ($attributes['response_breached'] ?? false) {
            $breachedKinds[] = 'response';
        }
        if ($attributes['resolution_breached'] ?? false) {
            $breachedKinds[] = 'resolution';
        }
        if ($breachedKinds !== []) {
            SlaAtRisk::dispatch($ticket->refresh(), implode('+', $breachedKinds));
        }

        activity()
            ->performedOn($ticket)
            ->causedBy($actor)
            ->withChanges(['old' => $oldValues, 'attributes' => $attributes])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('support.ticket.priority_changed');
    }

    /**
     * Sets a breach flag once its due time has passed without the
     * corresponding event (FR-054). Sticky — never clears an already-set
     * flag (FR-057). Called from a scheduled sweep
     * ({@see ReconcileSlaBreachesCommand}); a no-op
     * write is skipped entirely.
     */
    public function refreshBreachFlags(Ticket $ticket): void
    {
        $now = now();
        $attributes = [];

        if (! $ticket->response_breached
            && $ticket->response_due_at !== null
            && $ticket->first_response_at === null
            && $now->gt($ticket->response_due_at)) {
            $attributes['response_breached'] = true;
        }

        if (! $ticket->resolution_breached
            && $ticket->resolution_due_at !== null
            && $ticket->resolved_at === null
            && $now->gt($ticket->resolution_due_at)) {
            $attributes['resolution_breached'] = true;
        }

        if ($attributes !== []) {
            $ticket->update($attributes);

            $breachedKinds = [];
            if ($attributes['response_breached'] ?? false) {
                $breachedKinds[] = 'response';
            }
            if ($attributes['resolution_breached'] ?? false) {
                $breachedKinds[] = 'resolution';
            }

            if ($breachedKinds !== []) {
                SlaAtRisk::dispatch($ticket->refresh(), implode('+', $breachedKinds));
            }
        }
    }

    private function policyFor(TicketPriority $priority): SlaPolicy
    {
        return SlaPolicy::query()->where('priority', $priority)->firstOrFail();
    }
}
