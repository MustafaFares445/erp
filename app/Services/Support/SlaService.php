<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\ResolvedSlaPolicy;
use App\Enums\SlaMilestoneKey;
use App\Enums\SupportAutomationEvent;
use App\Enums\TicketPriority;
use App\Events\SlaAtRisk;
use App\Exceptions\Domain\NoMatchingSlaPolicyException;
use App\Models\SlaPolicy;
use App\Models\SlaPolicyMilestone;
use App\Models\Ticket;
use App\Models\TicketSlaMilestone;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final readonly class SlaService
{
    public function __construct(
        private SlaPolicyResolver $policyResolver,
        private SlaMilestoneService $milestoneService,
        private SlaSummaryProjector $projector,
    ) {}

    public function onTicketCreated(Ticket $ticket): void
    {
        if (! $this->v2Enabled()) {
            $this->legacyOnTicketCreated($ticket);

            return;
        }

        if ($ticket->response_due_at !== null || $ticket->slaMilestones()->where('key', SlaMilestoneKey::FirstResponse->value)->exists()) {
            return;
        }

        $resolved = $this->resolveOrNull($ticket);

        if (! $resolved instanceof ResolvedSlaPolicy) {
            $this->legacyOnTicketCreated($ticket);

            return;
        }

        $policy = $resolved->policy;
        $responseTarget = $this->target($policy, SlaMilestoneKey::FirstResponse, $policy->response_target_minutes);
        $resolutionTarget = $this->target($policy, SlaMilestoneKey::Resolution, $policy->resolution_target_minutes);
        $startedAt = $ticket->created_at ?? now();

        $ticket->forceFill([
            'sla_policy_id' => $policy->getKey(),
            'support_entitlement_id' => $resolved->entitlement?->getKey(),
            'sla_response_target_minutes' => $responseTarget,
            'sla_resolution_target_minutes' => $resolutionTarget,
        ])->save();

        $this->milestoneService->start($ticket, SlaMilestoneKey::FirstResponse, $policy, $responseTarget, $startedAt);
        $this->projector->project($ticket->refresh());
    }

    public function onTicketLive(Ticket $ticket): void
    {
        if (! $this->v2Enabled()) {
            $this->legacyOnTicketLive($ticket);

            return;
        }

        if ($ticket->response_due_at === null
            && ! $ticket->slaMilestones()->where('key', SlaMilestoneKey::FirstResponse->value)->exists()) {
            $this->onTicketCreated($ticket);
            $ticket->refresh();
        }

        if ($ticket->live_at !== null) {
            return;
        }

        $resolved = $this->resolveOrNull($ticket);

        if (! $resolved instanceof ResolvedSlaPolicy) {
            $this->legacyOnTicketLive($ticket);

            return;
        }

        $policy = $resolved->policy;
        $resolutionTarget = $this->target($policy, SlaMilestoneKey::Resolution, $policy->resolution_target_minutes);
        $liveAt = now();

        $ticket->forceFill([
            'sla_policy_id' => $policy->getKey(),
            'support_entitlement_id' => $resolved->entitlement?->getKey(),
            'sla_resolution_target_minutes' => $resolutionTarget,
            'live_at' => $liveAt,
        ])->save();

        $this->milestoneService->start($ticket, SlaMilestoneKey::Resolution, $policy, $resolutionTarget, $liveAt);

        foreach ([SlaMilestoneKey::Assignment, SlaMilestoneKey::OnsiteArrival] as $optionalKey) {
            $definition = $policy->milestones->first(
                static fn (SlaPolicyMilestone $milestone): bool => $milestone->is_active && $milestone->key === $optionalKey,
            );

            if ($definition instanceof SlaPolicyMilestone
                && ($optionalKey !== SlaMilestoneKey::OnsiteArrival || $ticket->service_path?->value === 'on_site_visit')) {
                $this->milestoneService->start($ticket, $optionalKey, $policy, $definition->target_minutes, $liveAt);
            }
        }

        $this->projector->project($ticket->refresh());
    }

    public function onWaitingCustomer(Ticket $ticket): void
    {
        if (! $this->v2Enabled()) {
            $ticket->update(['waiting_customer_since' => now()]);

            return;
        }

        $at = now();
        $this->milestoneService->pause($ticket, SlaMilestoneKey::Resolution, $at);
        $this->projector->project($ticket->refresh());

        if ($ticket->waiting_customer_since === null) {
            $ticket->forceFill(['waiting_customer_since' => $at])->save();
        }
    }

    public function onResumeFromWaiting(Ticket $ticket): void
    {
        if (! $this->v2Enabled()) {
            $this->legacyResume($ticket);

            return;
        }

        $milestone = $this->milestoneService->resume($ticket, SlaMilestoneKey::Resolution);

        if (! $milestone instanceof TicketSlaMilestone) {
            if ($ticket->waiting_customer_since !== null) {
                $this->legacyResume($ticket);
            }

            return;
        }

        $this->projector->project($ticket->refresh());
    }

    public function onPriorityChanged(Ticket $ticket, TicketPriority $newPriority, ?User $actor): void
    {
        if (! $this->v2Enabled()) {
            $this->legacyPriorityChanged($ticket, $newPriority, $actor);

            return;
        }

        if ($ticket->response_due_at === null && $ticket->live_at === null) {
            return;
        }

        $resolved = $this->resolveOrNull($ticket, $newPriority);

        if (! $resolved instanceof ResolvedSlaPolicy) {
            $this->legacyPriorityChanged($ticket, $newPriority, $actor);

            return;
        }

        $policy = $resolved->policy;
        $responseTarget = $this->target($policy, SlaMilestoneKey::FirstResponse, $policy->response_target_minutes);
        $resolutionTarget = $this->target($policy, SlaMilestoneKey::Resolution, $policy->resolution_target_minutes);
        $responseAnchor = $ticket->live_at ?? $ticket->response_sla_started_at ?? $ticket->created_at ?? now();
        $oldValues = $ticket->only(['response_due_at', 'resolution_due_at']);

        $this->milestoneService->resnapshot(
            $ticket,
            SlaMilestoneKey::FirstResponse,
            $policy,
            $responseTarget,
            $responseAnchor,
        );

        if ($ticket->live_at !== null) {
            $this->milestoneService->resnapshot(
                $ticket,
                SlaMilestoneKey::Resolution,
                $policy,
                $resolutionTarget,
                $ticket->live_at,
                $ticket->waiting_customer_accumulated_seconds,
            );
        }

        $ticket->forceFill([
            'sla_policy_id' => $policy->getKey(),
            'support_entitlement_id' => $resolved->entitlement?->getKey(),
            'sla_response_target_minutes' => $responseTarget,
            'sla_resolution_target_minutes' => $resolutionTarget,
        ])->save();

        $this->projector->project($ticket->refresh());
        $ticket->refresh();

        $breachedKinds = [];
        if ($ticket->response_breached) {
            $breachedKinds[] = 'response';
        }
        if ($ticket->resolution_breached) {
            $breachedKinds[] = 'resolution';
        }
        if ($breachedKinds !== []) {
            SlaAtRisk::dispatch($ticket, implode('+', $breachedKinds));
        }

        $activity = activity()
            ->performedOn($ticket)
            ->withChanges([
                'old' => $oldValues,
                'attributes' => [
                    'response_due_at' => $ticket->response_due_at,
                    'resolution_due_at' => $ticket->resolution_due_at,
                    'sla_policy_id' => $ticket->sla_policy_id,
                ],
            ])
            ->withProperties([
                'source_channel' => $actor instanceof User ? 'dashboard' : 'automation',
                'ip_address' => $actor instanceof User ? request()->ip() : null,
            ]);

        if ($actor instanceof User) {
            $activity->causedBy($actor);
        }

        $activity->log('support.ticket.priority_changed');
    }

    public function refreshBreachFlags(Ticket $ticket): void
    {
        if (! $this->v2Enabled()) {
            $this->legacyRefreshBreachFlags($ticket);

            return;
        }

        if (! $ticket->slaMilestones()->exists()) {
            // Tickets started without a matching policy keep the built-in priority clocks.
            $this->legacyRefreshBreachFlags($ticket);

            return;
        }

        $newlyBreached = $this->milestoneService->refreshBreaches($ticket);

        if ($newlyBreached === []) {
            return;
        }

        $this->projector->project($ticket->refresh());

        $fresh = $ticket->refresh();
        $kind = implode('+', array_map(static fn (SlaMilestoneKey $key): string => $key->value, $newlyBreached));

        SlaAtRisk::dispatch($fresh, $kind);

        app(SupportAutomationEngine::class)->handle(
            SupportAutomationEvent::SlaBreached,
            $fresh,
            ['milestones' => array_map(static fn (SlaMilestoneKey $key): string => $key->value, $newlyBreached)],
            hash('sha256', 'sla-breach|'.$fresh->id.'|'.$kind.'|'.($fresh->response_due_at->timestamp ?? 0).'|'.($fresh->resolution_due_at->timestamp ?? 0)),
        );
    }

    public function completeFirstResponse(Ticket $ticket): void
    {
        if (! $this->v2Enabled()) {
            return;
        }

        $this->milestoneService->complete($ticket, SlaMilestoneKey::FirstResponse, $ticket->first_response_at ?? now());
        $this->projector->project($ticket->refresh());
    }

    public function completeResolution(Ticket $ticket): void
    {
        if (! $this->v2Enabled()) {
            return;
        }

        $this->milestoneService->complete($ticket, SlaMilestoneKey::Resolution, $ticket->resolved_at ?? now());
        $this->projector->project($ticket->refresh());
    }

    public function completeAssignment(Ticket $ticket): void
    {
        if (! $this->v2Enabled()) {
            return;
        }

        $this->milestoneService->complete($ticket, SlaMilestoneKey::Assignment);
    }

    public function completeOnsiteArrival(Ticket $ticket): void
    {
        if (! $this->v2Enabled()) {
            return;
        }

        $this->milestoneService->complete($ticket, SlaMilestoneKey::OnsiteArrival);
    }

    public function reopenResolution(Ticket $ticket): void
    {
        if (! $this->v2Enabled()) {
            return;
        }

        $this->milestoneService->reopen($ticket, SlaMilestoneKey::Resolution);
        $this->projector->project($ticket->refresh());
    }

    private function target(SlaPolicy $policy, SlaMilestoneKey $key, int $fallback): int
    {
        $definition = $policy->milestones->first(
            static fn (SlaPolicyMilestone $milestone): bool => $milestone->is_active && $milestone->key === $key,
        );

        return $definition instanceof SlaPolicyMilestone ? $definition->target_minutes : $fallback;
    }

    /**
     * A missing policy must never block intake: the ticket then runs on the built-in priority targets and
     * the gap is logged so an administrator can add a matching policy.
     */
    private function resolveOrNull(Ticket $ticket, ?TicketPriority $priority = null): ?ResolvedSlaPolicy
    {
        try {
            return $this->policyResolver->resolve($ticket, $priority);
        } catch (NoMatchingSlaPolicyException) {
            Log::warning('support.sla.no_matching_policy', ['ticket_id' => $ticket->getKey(), 'priority' => ($priority ?? $ticket->priority)->value]);

            return null;
        }
    }

    private function v2Enabled(): bool
    {
        return (bool) config('support.sla_v2_enabled', false);
    }

    private function legacyOnTicketCreated(Ticket $ticket): void
    {
        if ($ticket->response_due_at !== null) {
            return;
        }

        $policy = $this->legacyPolicyFor($ticket->priority);
        $startedAt = $ticket->created_at ?? now();

        $ticket->update([
            'sla_response_target_minutes' => $policy->response_target_minutes,
            'sla_resolution_target_minutes' => $policy->resolution_target_minutes,
            'response_sla_started_at' => $startedAt,
            'response_due_at' => $startedAt->clone()->addMinutes($policy->response_target_minutes),
        ]);
    }

    private function legacyOnTicketLive(Ticket $ticket): void
    {
        if ($ticket->live_at !== null) {
            return;
        }

        $policy = $this->legacyPolicyFor($ticket->priority);
        $liveAt = now();
        $responseTarget = $ticket->sla_response_target_minutes ?? $policy->response_target_minutes;
        $resolutionTarget = $ticket->sla_resolution_target_minutes ?? $policy->resolution_target_minutes;
        $responseStartedAt = $ticket->response_sla_started_at ?? $ticket->created_at ?? $liveAt;

        $ticket->update([
            'sla_response_target_minutes' => $responseTarget,
            'sla_resolution_target_minutes' => $resolutionTarget,
            'live_at' => $liveAt,
            'response_due_at' => $ticket->response_due_at ?? $responseStartedAt->clone()->addMinutes($responseTarget),
            'resolution_due_at' => $liveAt->clone()->addMinutes($resolutionTarget),
        ]);
    }

    private function legacyResume(Ticket $ticket): void
    {
        if ($ticket->waiting_customer_since === null) {
            return;
        }

        $elapsedSeconds = (int) $ticket->waiting_customer_since->diffInSeconds(now());
        $ticket->update([
            'waiting_customer_accumulated_seconds' => $ticket->waiting_customer_accumulated_seconds + $elapsedSeconds,
            'resolution_due_at' => $ticket->resolution_due_at?->clone()->addSeconds($elapsedSeconds),
            'waiting_customer_since' => null,
        ]);
    }

    private function legacyPriorityChanged(Ticket $ticket, TicketPriority $newPriority, ?User $actor): void
    {
        if ($ticket->response_due_at === null && $ticket->live_at === null) {
            return;
        }

        $policy = $this->legacyPolicyFor($newPriority);
        $responseAnchorAt = $ticket->live_at ?? $ticket->response_sla_started_at ?? $ticket->created_at ?? now();
        $responseDueAt = $responseAnchorAt->clone()->addMinutes($policy->response_target_minutes);
        $resolutionDueAt = $ticket->live_at?->clone()
            ->addMinutes($policy->resolution_target_minutes)
            ->addSeconds($ticket->waiting_customer_accumulated_seconds);
        $attributes = [
            'sla_response_target_minutes' => $policy->response_target_minutes,
            'sla_resolution_target_minutes' => $policy->resolution_target_minutes,
            'response_due_at' => $responseDueAt,
            'resolution_due_at' => $resolutionDueAt,
        ];

        if (! $ticket->response_breached && $ticket->first_response_at === null && now()->gt($responseDueAt)) {
            $attributes['response_breached'] = true;
        }

        if ($resolutionDueAt !== null && ! $ticket->resolution_breached && $ticket->resolved_at === null && now()->gt($resolutionDueAt)) {
            $attributes['resolution_breached'] = true;
        }

        $oldValues = $ticket->only(['response_due_at', 'resolution_due_at']);
        $ticket->update($attributes);

        if (($attributes['response_breached'] ?? false) || ($attributes['resolution_breached'] ?? false)) {
            $kinds = array_filter([
                ($attributes['response_breached'] ?? false) ? 'response' : null,
                ($attributes['resolution_breached'] ?? false) ? 'resolution' : null,
            ]);
            SlaAtRisk::dispatch($ticket->refresh(), implode('+', $kinds));
        }

        $activity = activity()
            ->performedOn($ticket)
            ->withChanges(['old' => $oldValues, 'attributes' => $attributes])
            ->withProperties([
                'source_channel' => $actor instanceof User ? 'dashboard' : 'automation',
                'ip_address' => $actor instanceof User ? request()->ip() : null,
            ]);

        if ($actor instanceof User) {
            $activity->causedBy($actor);
        }

        $activity->log('support.ticket.priority_changed');
    }

    private function legacyRefreshBreachFlags(Ticket $ticket): void
    {
        $attributes = [];

        if (! $ticket->response_breached && $ticket->response_due_at !== null && $ticket->first_response_at === null && now()->gt($ticket->response_due_at)) {
            $attributes['response_breached'] = true;
        }

        if (! $ticket->resolution_breached && $ticket->resolution_due_at !== null && $ticket->resolved_at === null && now()->gt($ticket->resolution_due_at)) {
            $attributes['resolution_breached'] = true;
        }

        if ($attributes === []) {
            return;
        }

        $ticket->update($attributes);
        $kinds = array_filter([
            ($attributes['response_breached'] ?? false) ? 'response' : null,
            ($attributes['resolution_breached'] ?? false) ? 'resolution' : null,
        ]);

        if ($kinds !== []) {
            SlaAtRisk::dispatch($ticket->refresh(), implode('+', $kinds));
        }
    }

    private function legacyPolicyFor(TicketPriority $priority): SlaPolicy
    {
        $policy = SlaPolicy::query()->where('priority', $priority)->orderBy('precedence')->first();

        if ($policy instanceof SlaPolicy) {
            return $policy;
        }

        [$response, $resolution] = match ($priority) {
            TicketPriority::Urgent => [60, 240],
            TicketPriority::High => [240, 1440],
            TicketPriority::Normal => [480, 2880],
            TicketPriority::Low => [1440, 4320],
        };

        return (new SlaPolicy)->forceFill([
            'priority' => $priority,
            'response_target_minutes' => $response,
            'resolution_target_minutes' => $resolution,
        ]);
    }
}
