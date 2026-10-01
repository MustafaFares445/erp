<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceStatus;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\User;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class TicketLifecycleService
{
    public function __construct(
        private TicketPaymentService $paymentService,
        private SlaService $slaService,
    ) {}

    public function transition(Ticket $ticket, TicketStatus $to, User $actor, ?string $note = null): void
    {
        $ticket->refresh();
        $this->authorizeTransition($ticket, $to, $actor);

        DB::transaction(function () use ($ticket, $to, $actor, $note): void {
            $locked = $this->lockedTicket($ticket);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }

            // New tickets must leave Pending through TicketTriageService so the
            // equipment, warranty, service path and billing decision are captured
            // atomically. Payment activation also remains service-owned.
            if ($from === TicketStatus::Pending && in_array($to, [TicketStatus::Live, TicketStatus::PendingPayment], true)) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }

            if ($from === TicketStatus::PendingPayment && $to === TicketStatus::Live) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }

            if ($to === TicketStatus::Resolved && mb_trim((string) $note) === '') {
                throw ValidationException::withMessages([
                    'resolution_summary' => 'A resolution summary is required before resolving the ticket.',
                ]);
            }

            if ($to === TicketStatus::Closed
                && $locked->maintenanceRecords()->whereNotIn('status', [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled])->exists()) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }

            $attributes = ['status' => $to->value, 'updated_by' => $actor->getKey()];
            $isReopen = $from === TicketStatus::Resolved && $to === TicketStatus::InProgress;

            if ($to === TicketStatus::Resolved) {
                $attributes['resolved_at'] = now();
                $attributes['resolution_summary'] = mb_trim((string) $note);
            } elseif ($isReopen) {
                $attributes['resolved_at'] = null;
                $attributes['resolution_summary'] = null;
            }

            $locked->update($attributes);

            if ($from === TicketStatus::PendingPayment && $to === TicketStatus::Cancelled) {
                $this->paymentService->cancelForTicket($locked);
            }

            if ($to === TicketStatus::Live) {
                $this->slaService->onTicketLive($locked);
            } elseif ($to === TicketStatus::WaitingCustomer) {
                $this->slaService->onWaitingCustomer($locked);
            } elseif ($from === TicketStatus::WaitingCustomer) {
                $this->slaService->onResumeFromWaiting($locked);
            }

            if ($isReopen) {
                $this->slaService->refreshBreachFlags($locked);
            }

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => $from->value],
                    'attributes' => ['status' => $to->value, 'note' => $note],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.ticket.status_changed');

            DB::afterCommit(static fn () => TicketUpdated::dispatch(
                $ticket->refresh()->load('customer.user'),
            ));
        });
    }

    public function assign(Ticket $ticket, EmployeeProfile $employee, User $actor): void
    {
        $ticket->refresh();
        Gate::forUser($actor)->authorize('assign', $ticket);

        DB::transaction(function () use ($ticket, $employee, $actor): void {
            $locked = $this->lockedTicket($ticket);

            if (! in_array($locked->status, [TicketStatus::Live, TicketStatus::Assigned, TicketStatus::InProgress], true)) {
                throw InvalidStatusTransition::fromTo($locked->status->value, TicketStatus::Assigned->value);
            }

            TicketAssignment::query()->create([
                'ticket_id' => $locked->getKey(),
                'employee_id' => $employee->getKey(),
                'assigned_by' => $actor->getKey(),
                'assigned_at' => now(),
            ]);

            $attributes = [
                'assigned_employee_id' => $employee->getKey(),
                'updated_by' => $actor->getKey(),
            ];

            if ($locked->status === TicketStatus::Live) {
                $attributes['status'] = TicketStatus::Assigned->value;
            }

            $locked->update($attributes);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['assigned_employee_id' => $employee->getKey()]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.ticket.assigned');

            DB::afterCommit(static fn () => TicketUpdated::dispatch(
                $ticket->refresh()->load('customer.user'),
            ));
        });
    }

    public function unassign(Ticket $ticket, User $actor): void
    {
        $ticket->refresh();
        Gate::forUser($actor)->authorize('assign', $ticket);

        DB::transaction(function () use ($ticket, $actor): void {
            $locked = $this->lockedTicket($ticket);

            if ($locked->status !== TicketStatus::Assigned) {
                throw InvalidStatusTransition::fromTo($locked->status->value, TicketStatus::Live->value);
            }

            $locked->update([
                'assigned_employee_id' => null,
                'status' => TicketStatus::Live->value,
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['assigned_employee_id' => null, 'status' => TicketStatus::Live->value]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.ticket.unassigned');

            DB::afterCommit(static fn () => TicketUpdated::dispatch(
                $ticket->refresh()->load('customer.user'),
            ));
        });
    }

    /**
     * Re-reads the ticket under a row lock so every status guard runs against
     * the committed state — a settlement, cancel or assignment that raced the
     * caller's refresh must be seen, never overwritten.
     */
    private function lockedTicket(Ticket $ticket): Ticket
    {
        return Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
    }

    private function authorizeTransition(Ticket $ticket, TicketStatus $to, User $actor): void
    {
        if (in_array($to, [TicketStatus::Live, TicketStatus::Cancelled], true)) {
            Gate::forUser($actor)->authorize('update', $ticket);

            return;
        }

        Gate::forUser($actor)->authorize('work', $ticket);
    }
}
