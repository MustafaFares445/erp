<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\ResolvedSlaPolicy;
use App\Enums\SupportEntitlementStatus;
use App\Enums\TicketPriority;
use App\Exceptions\Domain\NoMatchingSlaPolicyException;
use App\Models\SlaPolicy;
use App\Models\SupportEntitlement;
use App\Models\Ticket;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class SlaPolicyResolver
{
    public function resolve(Ticket $ticket, ?TicketPriority $priority = null): ResolvedSlaPolicy
    {
        $priority ??= $ticket->priority;
        $entitlement = $this->entitlementFor($ticket);

        /** @var Collection<int, SlaPolicy> $policies */
        $policies = SlaPolicy::query()
            ->where('is_active', true)
            ->with(['calendar.periods', 'calendar.exceptions', 'milestones'])
            ->orderBy('precedence')
            ->orderBy('id')
            ->get();

        $matches = $policies
            ->filter(fn (SlaPolicy $policy): bool => $this->matches($policy, $ticket, $priority, $entitlement))
            ->sort(static fn (SlaPolicy $left, SlaPolicy $right): int => [
                $left->precedence,
                -$left->specificity(),
                $left->id,
            ] <=> [
                $right->precedence,
                -$right->specificity(),
                $right->id,
            ])
            ->values();

        $policy = $matches->first();

        if (! $policy instanceof SlaPolicy) {
            throw NoMatchingSlaPolicyException::forTicket();
        }

        $equallyRanked = $matches->filter(
            static fn (SlaPolicy $candidate): bool => $candidate->precedence === $policy->precedence
                && $candidate->specificity() === $policy->specificity(),
        );

        if ($equallyRanked->count() > 1) {
            throw new DomainException('More than one SLA policy matches with the same precedence and specificity.');
        }

        return new ResolvedSlaPolicy($policy, $entitlement);
    }

    private function entitlementFor(Ticket $ticket): ?SupportEntitlement
    {
        $query = SupportEntitlement::query()
            ->where('customer_id', $ticket->customer_id)
            ->where('status', SupportEntitlementStatus::Active->value)
            ->whereDate('starts_on', '<=', today())
            ->where(static fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()));

        if ($ticket->serialized_inventory_unit_id !== null) {
            $specific = (clone $query)
                ->where('serialized_inventory_unit_id', $ticket->serialized_inventory_unit_id)
                ->orderByDesc('starts_on')
                ->first();

            if ($specific instanceof SupportEntitlement) {
                return $specific;
            }
        }

        return $query
            ->whereNull('serialized_inventory_unit_id')
            ->orderByDesc('starts_on')
            ->first();
    }

    private function matches(
        SlaPolicy $policy,
        Ticket $ticket,
        TicketPriority $priority,
        ?SupportEntitlement $entitlement,
    ): bool {
        if ($policy->priority !== null && $policy->priority !== $priority) {
            return false;
        }

        if ($policy->ticket_type !== null && $policy->ticket_type !== $ticket->type) {
            return false;
        }

        if ($policy->service_path !== null && $policy->service_path !== $ticket->service_path) {
            return false;
        }

        if ($policy->support_team_id !== null && $policy->support_team_id !== $ticket->support_team_id) {
            return false;
        }

        if ($policy->customer_id !== null && $policy->customer_id !== $ticket->customer_id) {
            return false;
        }

        if ($policy->product_variant_id !== null
            && $policy->product_variant_id !== $ticket->serializedInventoryUnit?->product_variant_id) {
            return false;
        }

        if ($policy->support_service_level_id !== null
            && $policy->support_service_level_id !== $entitlement?->support_service_level_id) {
            return false;
        }

        return true;
    }
}
