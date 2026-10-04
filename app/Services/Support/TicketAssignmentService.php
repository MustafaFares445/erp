<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\SupportAutomationEvent;
use App\Enums\TicketAssignmentSource;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\EmployeeProfile;
use App\Models\SupportRoutingRule;
use App\Models\SupportTeam;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\User;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class TicketAssignmentService
{
    public function assign(
        Ticket $ticket,
        EmployeeProfile $employee,
        ?User $actor,
        TicketAssignmentSource $source = TicketAssignmentSource::Manual,
        ?SupportTeam $team = null,
        ?SupportRoutingRule $rule = null,
        ?string $reason = null,
    ): TicketAssignment {
        if (! $employee->is_active) {
            throw new DomainException('Inactive employees cannot receive support tickets.');
        }

        if ($source === TicketAssignmentSource::Manual) {
            if (! $actor instanceof User) {
                throw new DomainException('Manual ticket assignment requires an authenticated actor.');
            }

            Gate::forUser($actor)->authorize('assign', $ticket);
        }

        return DB::transaction(function () use ($ticket, $employee, $actor, $source, $team, $rule, $reason): TicketAssignment {
            $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [TicketStatus::Live, TicketStatus::Assigned, TicketStatus::InProgress], true)) {
                throw InvalidStatusTransition::fromTo($locked->status->value, TicketStatus::Assigned->value);
            }

            $assignment = TicketAssignment::query()->create([
                'ticket_id' => $locked->getKey(),
                'employee_id' => $employee->getKey(),
                'assigned_by' => $actor?->getKey(),
                'assigned_at' => now(),
                'assignment_source' => $source,
                'support_team_id' => $team?->getKey() ?? $locked->support_team_id,
                'routing_rule_id' => $rule?->getKey(),
                'reason' => $reason,
            ]);

            $attributes = [
                'assigned_employee_id' => $employee->getKey(),
                'support_team_id' => $team?->getKey() ?? $locked->support_team_id,
                'updated_by' => $actor?->getKey() ?? $locked->updated_by,
            ];

            if ($locked->status === TicketStatus::Live) {
                $attributes['status'] = TicketStatus::Assigned->value;
            }

            $locked->update($attributes);

            $activity = activity()
                ->performedOn($locked)
                ->withChanges(['attributes' => [
                    'assigned_employee_id' => $employee->getKey(),
                    'support_team_id' => $attributes['support_team_id'],
                    'assignment_source' => $source->value,
                    'routing_rule_id' => $rule?->getKey(),
                    'reason' => $reason,
                ]])
                ->withProperties(['source_channel' => $source === TicketAssignmentSource::Manual ? 'dashboard' : 'system']);

            if ($actor instanceof User) {
                $activity->causedBy($actor);
            }

            $activity->log('support.ticket.assigned');

            app(SlaService::class)->completeAssignment($locked->refresh());

            DB::afterCommit(static function () use ($ticket, $assignment, $source): void {
                $fresh = $ticket->refresh();
                TicketUpdated::dispatch($fresh->load('customer.user'));

                if ($source !== TicketAssignmentSource::Automation) {
                    app(SupportAutomationEngine::class)->handle(
                        SupportAutomationEvent::TicketAssigned,
                        $fresh,
                        [
                            'assignment_id' => $assignment->getKey(),
                            'assignment_source' => $source->value,
                        ],
                    );
                }
            });

            return $assignment;
        });
    }

    public function unassign(Ticket $ticket, User $actor): void
    {
        Gate::forUser($actor)->authorize('assign', $ticket);

        DB::transaction(function () use ($ticket, $actor): void {
            $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

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
}
