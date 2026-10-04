<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\SupportAutomationAction;
use App\Enums\TicketAssignmentSource;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Models\CollaborationEntry;
use App\Models\CollaborationFollower;
use App\Models\EmployeeProfile;
use App\Models\SupportTeam;
use App\Models\Ticket;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;

final readonly class SupportAutomationActionExecutor
{
    public function __construct(
        private TicketAssignmentService $assignmentService,
        private SupportWorkloadService $workloadService,
        private SlaService $slaService,
        private TicketMessageService $messageService,
    ) {}

    /** @param array<mixed> $action */
    public function execute(Model $subject, array $action): string
    {
        $type = SupportAutomationAction::tryFrom($this->text($action, 'type'));

        if (! $type instanceof SupportAutomationAction) {
            throw new DomainException('Unsupported support automation action.');
        }

        if (! $subject instanceof Ticket) {
            throw new DomainException('This automation action currently requires a ticket subject.');
        }

        return match ($type) {
            SupportAutomationAction::AssignTeam => $this->assignTeam($subject, $action),
            SupportAutomationAction::AutoAssign => $this->autoAssign($subject),
            SupportAutomationAction::SetPriority => $this->setPriority($subject, $action),
            SupportAutomationAction::PostInternalNote => $this->postInternalNote($subject, $action),
            SupportAutomationAction::AddFollower => $this->addFollower($subject, $action),
            SupportAutomationAction::CreateFollowUp => $this->createFollowUp($subject, $action),
        };
    }

    /** @param array<mixed> $action */
    private function assignTeam(Ticket $ticket, array $action): string
    {
        $teamId = $action['team_id'] ?? $action['value'] ?? null;

        if (! is_numeric($teamId)) {
            throw new DomainException('Assign-team automation requires a team_id.');
        }

        $team = SupportTeam::query()->where('is_active', true)->findOrFail((int) $teamId);
        $ticket->forceFill(['support_team_id' => $team->getKey(), 'routed_at' => now()])->save();

        activity()
            ->performedOn($ticket)
            ->withChanges(['attributes' => ['support_team_id' => $team->getKey()]])
            ->withProperties(['source_channel' => 'automation'])
            ->log('support.ticket.routed');

        return 'Assigned team '.$team->name;
    }

    private function autoAssign(Ticket $ticket): string
    {
        $team = $ticket->supportTeam;

        if (! $team instanceof SupportTeam || ! $team->is_active) {
            throw new DomainException('Auto-assignment requires an active support team.');
        }

        $candidates = [];

        foreach ($team->members()->where('is_active', true)->with(['employee.user', 'team'])->get() as $member) {
            $employee = $member->employee;
            if ($employee === null) {
                continue;
            }
            if ($employee->is_active !== true) {
                continue;
            }

            $acceptsServicePath = match ($ticket->service_path) {
                TicketServicePath::OnSiteVisit => $member->accepts_onsite,
                TicketServicePath::RemoteSupport => $member->accepts_remote,
                default => true,
            };
            if (! $acceptsServicePath) {
                continue;
            }
            if (! $this->workloadService->hasCapacity($member)) {
                continue;
            }

            $candidates[] = [
                'employee' => $employee,
                'rank' => sprintf(
                    '%010d-%010d-%010d',
                    $this->workloadService->openTicketCount($employee),
                    100000 - $member->routing_weight,
                    $member->employee_id,
                ),
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp($a['rank'], $b['rank']));

        $employee = $candidates[0]['employee'] ?? null;

        if (! $employee instanceof EmployeeProfile) {
            throw new DomainException('No eligible support team member has capacity for auto-assignment.');
        }

        $this->assignmentService->assign(
            $ticket->refresh(),
            $employee,
            null,
            TicketAssignmentSource::Automation,
            $team,
            null,
            'Assigned by support automation.',
        );

        return 'Auto-assigned to '.($employee->user->name ?? $employee->employee_code);
    }

    /** @param array<mixed> $action */
    private function setPriority(Ticket $ticket, array $action): string
    {
        $priority = TicketPriority::tryFrom($this->text($action, 'priority', 'value'));

        if (! $priority instanceof TicketPriority) {
            throw new DomainException('Set-priority automation requires a valid priority.');
        }

        $old = $ticket->priority;
        $ticket->forceFill(['priority' => $priority])->save();

        if ($old !== $priority) {
            $this->slaService->onPriorityChanged($ticket->refresh(), $priority, null);
        }

        return 'Priority set to '.$priority->label();
    }

    /** @param array<mixed> $action */
    private function postInternalNote(Ticket $ticket, array $action): string
    {
        $body = mb_trim($this->text($action, 'message', 'value'));
        $userId = $action['user_id'] ?? null;

        if ($body === '' || ! is_numeric($userId)) {
            throw new DomainException('Internal-note automation requires a message and user_id.');
        }

        $actor = User::query()->findOrFail((int) $userId);
        $this->messageService->post($ticket, $body, true, $actor, 'automation');

        return 'Posted internal note';
    }

    /** @param array<mixed> $action */
    private function addFollower(Ticket $ticket, array $action): string
    {
        $userId = $action['user_id'] ?? $action['value'] ?? null;

        if (! is_numeric($userId)) {
            throw new DomainException('Add-follower automation requires a user_id.');
        }

        $user = User::query()->findOrFail((int) $userId);

        CollaborationFollower::query()->firstOrCreate([
            'subject_type' => $ticket->getMorphClass(),
            'subject_id' => $ticket->getKey(),
            'user_id' => $user->getKey(),
        ]);

        return 'Added follower '.$user->name;
    }

    /** @param array<mixed> $action */
    private function createFollowUp(Ticket $ticket, array $action): string
    {
        $authorId = $action['author_id'] ?? null;
        $body = mb_trim($this->text($action, 'body', 'value'));

        if (! is_numeric($authorId) || $body === '') {
            throw new DomainException('Create-follow-up automation requires author_id and body.');
        }

        $author = User::query()->findOrFail((int) $authorId);
        $assigneeId = is_numeric($action['assignee_id'] ?? null) ? (int) $action['assignee_id'] : null;
        $dueHours = is_numeric($action['due_hours'] ?? null) ? max(1, (int) $action['due_hours']) : null;

        $entry = CollaborationEntry::query()->create([
            'subject_type' => $ticket->getMorphClass(),
            'subject_id' => $ticket->getKey(),
            'type' => 'task',
            'author_id' => $author->getKey(),
            'assignee_id' => $assigneeId,
            'body' => $body,
            'due_at' => $dueHours !== null ? now()->addHours($dueHours) : null,
            'visibility' => 'internal',
            'metadata' => ['source' => 'support_automation'],
        ]);

        return 'Created follow-up task #'.$entry->id;
    }

    /**
     * First non-null scalar among the given action keys as a string, or an empty string when none is set.
     *
     * @param  array<mixed>  $action
     */
    private function text(array $action, string ...$keys): string
    {
        foreach ($keys as $key) {
            $value = $action[$key] ?? null;

            if ($value !== null) {
                return is_scalar($value) ? (string) $value : '';
            }
        }

        return '';
    }
}
