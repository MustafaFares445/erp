<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\RoutingDecision;
use App\Enums\SupportAssignmentStrategy;
use App\Enums\TicketAssignmentSource;
use App\Enums\TicketServicePath;
use App\Models\EmployeeProfile;
use App\Models\SupportRoutingRule;
use App\Models\SupportTeam;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class TicketRoutingService
{
    public function __construct(
        private SupportWorkloadService $workload,
        private TicketAssignmentService $assignmentService,
    ) {}

    public function route(Ticket $ticket): ?RoutingDecision
    {
        $ticket->loadMissing([
            'customer',
            'serializedInventoryUnit.productVariant.product',
        ]);

        $rule = $this->matchingRule($ticket);

        if (! $rule instanceof SupportRoutingRule) {
            return null;
        }

        $team = $rule->team;

        if (! $team instanceof SupportTeam || ! $team->is_active) {
            return null;
        }

        // Routing proposes an assignee only for unassigned live work; it never overrides a manual (or earlier) assignment.
        $employee = $rule->auto_assign
            && $ticket->assigned_employee_id === null
            && in_array($ticket->status->value, ['live', 'assigned', 'in_progress'], true)
            ? $this->selectEmployee($ticket, $team, $rule)
            : null;
        $reason = $this->explanation($rule, $team, $employee);

        DB::transaction(function () use ($ticket, $team, $rule, $employee, $reason): void {
            $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $locked->update([
                'support_team_id' => $team->getKey(),
                'routed_at' => now(),
                'routed_by_rule_id' => $rule->getKey(),
            ]);

            activity()
                ->performedOn($locked)
                ->withChanges(['attributes' => [
                    'support_team_id' => $team->getKey(),
                    'routed_by_rule_id' => $rule->getKey(),
                    'employee_id' => $employee?->getKey(),
                ]])
                ->withProperties(['source_channel' => 'system', 'reason' => $reason])
                ->log('support.ticket.routed');
        });

        if ($employee instanceof EmployeeProfile) {
            $this->assignmentService->assign(
                $ticket->refresh(),
                $employee,
                null,
                TicketAssignmentSource::Routing,
                $team,
                $rule,
                $reason,
            );
        }

        return new RoutingDecision($team, $employee, $rule, $reason);
    }

    private function matchingRule(Ticket $ticket): ?SupportRoutingRule
    {
        $variantId = $ticket->serializedInventoryUnit?->product_variant_id;
        $categoryId = $ticket->serializedInventoryUnit?->productVariant?->product?->category_id;
        $city = $ticket->customer?->city;

        return SupportRoutingRule::query()
            ->where('is_active', true)
            ->whereHas('team', static fn (Builder $query): Builder => $query->where('is_active', true))
            ->with(['team.members.employee', 'requiredSkill'])
            ->orderBy('precedence')
            ->orderBy('id')
            ->get()
            ->first(static fn (SupportRoutingRule $rule): bool => ($rule->ticket_type === null || $rule->ticket_type === $ticket->type)
                && ($rule->service_path === null || $rule->service_path === $ticket->service_path)
                && ($rule->product_variant_id === null || $rule->product_variant_id === $variantId)
                && ($rule->product_category_id === null || $rule->product_category_id === $categoryId)
                && ($rule->customer_city === null || mb_strtolower($rule->customer_city) === mb_strtolower((string) $city)));
    }

    private function selectEmployee(Ticket $ticket, SupportTeam $team, SupportRoutingRule $rule): ?EmployeeProfile
    {
        if ($team->assignment_strategy === SupportAssignmentStrategy::Manual) {
            return null;
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
            if (! $this->workload->hasCapacity($member)) {
                continue;
            }

            if ($rule->required_skill_id !== null
                && ! $employee->supportSkills()->whereKey($rule->required_skill_id)->exists()) {
                continue;
            }

            $candidates[] = [
                'employee' => $employee,
                'rank' => $team->assignment_strategy === SupportAssignmentStrategy::RoundRobin
                    ? $this->lastAssignmentAt($employee, $member->support_team_id).sprintf('-%010d', $member->employee_id)
                    : sprintf(
                        '%010d-%010d-%010d',
                        $this->workload->openTicketCount($employee),
                        100000 - $member->routing_weight,
                        $member->employee_id,
                    ),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp($a['rank'], $b['rank']));

        return $candidates[0]['employee'];
    }

    private function lastAssignmentAt(EmployeeProfile $employee, int $teamId): string
    {
        $last = $employee->supportTicketAssignments()
            ->where('support_team_id', $teamId)
            ->max('assigned_at');

        return is_string($last) ? $last : '1970-01-01 00:00:00';
    }

    private function explanation(
        SupportRoutingRule $rule,
        SupportTeam $team,
        ?EmployeeProfile $employee,
    ): string {
        $parts = [
            'Matched routing rule '.$rule->name.'.',
            'Team '.$team->name.'.',
        ];

        if ($rule->requiredSkill !== null) {
            $parts[] = 'Required skill '.$rule->requiredSkill->name.'.';
        }

        $parts[] = $employee instanceof EmployeeProfile
            ? 'Auto-assigned to '.($employee->user->name ?? $employee->employee_code).'.'
            : 'Ticket left in the team queue for manual assignment.';

        return implode(' ', $parts);
    }
}
