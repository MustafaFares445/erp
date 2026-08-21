<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceStatus;
use App\Enums\TicketStatus;
use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\User;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Ticket status transitions and assignment (FR-020–028,
 * contracts/ticket-lifecycle.md §1/§3). Every method self-checks
 * authorization in addition to whatever Filament's own `->authorize()`
 * already enforced, so a direct call bypassing the UI is rejected
 * identically (FR-006/008, research.md §4).
 */
final readonly class TicketLifecycleService
{
    public function __construct(
        private TicketPaymentService $paymentService,
        private SlaService $slaService,
    ) {}

    /**
     * @throws InvalidStatusTransition when `$from->canTransitionTo($to)` is false
     */
    public function transition(Ticket $ticket, TicketStatus $to, User $actor, ?string $note = null): void
    {
        $this->authorizeTransition($ticket, $to, $actor);

        $from = $ticket->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidStatusTransition::fromTo($from->value, $to->value);
        }

        if ($from === TicketStatus::PendingPayment && $to === TicketStatus::Live) {
            throw InvalidStatusTransition::fromTo($from->value, $to->value);
        }

        if ($to === TicketStatus::Closed
            && $ticket->maintenanceRecords()->whereNotIn('status', [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled])->exists()) {
            throw InvalidStatusTransition::fromTo($from->value, $to->value);
        }

        DB::transaction(function () use ($ticket, $from, $to, $actor, $note): void {
            $attributes = ['status' => $to->value, 'updated_by' => $actor->getKey()];

            $isReopen = $from === TicketStatus::Resolved && $to === TicketStatus::InProgress;

            if ($to === TicketStatus::Resolved) {
                $attributes['resolved_at'] = now();
            } elseif ($isReopen) {
                $attributes['resolved_at'] = null;
            }

            $ticket->update($attributes);

            if ($from === TicketStatus::PendingPayment && $to === TicketStatus::Cancelled) {
                $this->paymentService->cancelForTicket($ticket);
            }

            if ($to === TicketStatus::Live) {
                $this->slaService->onTicketLive($ticket);
            } elseif ($to === TicketStatus::WaitingCustomer) {
                $this->slaService->onWaitingCustomer($ticket);
            } elseif ($from === TicketStatus::WaitingCustomer) {
                $this->slaService->onResumeFromWaiting($ticket);
            }

            if ($isReopen) {
                // Resumes the original resolution clock without a fresh window (FR-058) — if
                // its due time has already passed, this immediately re-flags the breach rather
                // than waiting for the next scheduled sweep (spec.md Edge Cases).
                $this->slaService->refreshBreachFlags($ticket);
            }

            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => $from->value],
                    'attributes' => ['status' => $to->value, 'note' => $note],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.ticket.status_changed');
        });
    }

    /**
     * Creates a new append-only {@see TicketAssignment} row and updates the
     * ticket's current assignee (FR-023/024); the first assignment also
     * moves the ticket `live -> assigned`.
     */
    public function assign(Ticket $ticket, EmployeeProfile $employee, User $actor): void
    {
        Gate::forUser($actor)->authorize('assign', $ticket);

        if (! in_array($ticket->status, [TicketStatus::Live, TicketStatus::Assigned, TicketStatus::InProgress], true)) {
            throw InvalidStatusTransition::fromTo($ticket->status->value, TicketStatus::Assigned->value);
        }

        DB::transaction(function () use ($ticket, $employee, $actor): void {
            TicketAssignment::query()->create([
                'ticket_id' => $ticket->getKey(),
                'employee_id' => $employee->getKey(),
                'assigned_by' => $actor->getKey(),
                'assigned_at' => now(),
            ]);

            $wasLive = $ticket->status === TicketStatus::Live;

            $attributes = [
                'assigned_employee_id' => $employee->getKey(),
                'updated_by' => $actor->getKey(),
            ];

            if ($wasLive) {
                $attributes['status'] = TicketStatus::Assigned->value;
            }

            $ticket->update($attributes);

            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['assigned_employee_id' => $employee->getKey()]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.ticket.assigned');
        });
    }

    /**
     * Clears the current assignee and returns the ticket to `live`
     * (FR-022's `assigned -> live` edge). A distinct operation from
     * {@see self::transition()} because it also clears
     * `assigned_employee_id`, not just the status column.
     */
    public function unassign(Ticket $ticket, User $actor): void
    {
        Gate::forUser($actor)->authorize('assign', $ticket);

        if ($ticket->status !== TicketStatus::Assigned) {
            throw InvalidStatusTransition::fromTo($ticket->status->value, TicketStatus::Live->value);
        }

        DB::transaction(function () use ($ticket, $actor): void {
            $ticket->update([
                'assigned_employee_id' => null,
                'status' => TicketStatus::Live->value,
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['assigned_employee_id' => null, 'status' => TicketStatus::Live->value]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.ticket.unassigned');
        });
    }

    /**
     * Triage (`pending -> live`) and cancellation are Support
     * Manager-unrestricted actions (`ticket.manage`, reused via the
     * `update` ability); every other target status is the Support Agent's
     * own-ticket "work" ability, which a Support Manager also satisfies
     * unconditionally (`TicketPolicy::work()`). `pending_payment -> live` is
     * rejected above before authorization would even matter — that edge
     * belongs to {@see TicketPaymentService::settle()} alone (FR-043,
     * contracts/ticket-lifecycle.md §5).
     */
    private function authorizeTransition(Ticket $ticket, TicketStatus $to, User $actor): void
    {
        if (in_array($to, [TicketStatus::Live, TicketStatus::Cancelled], true)) {
            Gate::forUser($actor)->authorize('update', $ticket);

            return;
        }

        Gate::forUser($actor)->authorize('work', $ticket);
    }
}
