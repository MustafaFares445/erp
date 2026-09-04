<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\SalesOpportunityReviewStatus;
use App\Enums\SalesOpportunityStatus;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SalesOpportunityService
{
    /** @param array<string, mixed> $attributes */
    public function createManual(array $attributes, User $actor): SalesOpportunity
    {
        return DB::transaction(function () use ($attributes, $actor): SalesOpportunity {
            $summary = $attributes['summary'] ?? null;
            if (! is_string($summary) || mb_trim($summary) === '') {
                throw ValidationException::withMessages(['summary' => 'An opportunity summary is required.']);
            }

            $lead = $this->lead($attributes['lead_id'] ?? null);
            $customerId = $this->nullableId($attributes['customer_profile_id'] ?? null);
            $leadCustomerId = $lead?->converted_customer_id;

            if ($customerId !== null && is_numeric($leadCustomerId) && $customerId !== (int) $leadCustomerId) {
                throw new DomainException('The selected opportunity customer does not match the lead converted customer.');
            }

            $opportunity = SalesOpportunity::query()->create([
                'customer_profile_id' => $customerId ?? (is_numeric($leadCustomerId) ? (int) $leadCustomerId : null),
                'lead_id' => $lead?->getKey(),
                'owner_id' => $this->nullableId($attributes['owner_id'] ?? null)
                    ?? (is_numeric($lead?->assigned_to) ? (int) $lead->assigned_to : $actor->getKey()),
                'summary' => mb_trim($summary),
                'estimated_value' => $this->nullableAmount($attributes['estimated_value'] ?? null),
                'expected_close_date' => $this->nullableString($attributes['expected_close_date'] ?? null),
                'status' => SalesOpportunityStatus::Draft,
                'review_status' => SalesOpportunityReviewStatus::NotRequired,
            ]);

            activity()
                ->performedOn($opportunity)
                ->causedBy($actor)
                ->withProperties(['source' => 'manual'])
                ->log('opportunity.created');

            return $opportunity->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateDetails(SalesOpportunity $opportunity, array $attributes, User $actor): SalesOpportunity
    {
        if ($opportunity->status->isTerminal()) {
            throw new DomainException('A closed opportunity cannot be edited.');
        }

        return DB::transaction(function () use ($opportunity, $attributes, $actor): SalesOpportunity {
            $changes = [];

            if (array_key_exists('summary', $attributes)) {
                $summary = $attributes['summary'];
                if (! is_string($summary) || mb_trim($summary) === '') {
                    throw ValidationException::withMessages(['summary' => 'An opportunity summary is required.']);
                }
                $changes['summary'] = mb_trim($summary);
            }

            foreach (['customer_profile_id', 'lead_id', 'owner_id'] as $key) {
                if (array_key_exists($key, $attributes)) {
                    $changes[$key] = $this->nullableId($attributes[$key]);
                }
            }

            if (array_key_exists('estimated_value', $attributes)) {
                $changes['estimated_value'] = $this->nullableAmount($attributes['estimated_value']);
            }
            if (array_key_exists('expected_close_date', $attributes)) {
                $changes['expected_close_date'] = $this->nullableString($attributes['expected_close_date']);
            }

            $opportunity->update($changes);

            activity()->performedOn($opportunity)->causedBy($actor)->withChanges(['attributes' => $changes])->log('opportunity.updated');

            return $opportunity->refresh();
        });
    }

    public function qualify(SalesOpportunity $opportunity, User $actor): SalesOpportunity
    {
        if (! $opportunity->isQuotable()) {
            throw new DomainException('The opportunity must pass AI review before it can be qualified.');
        }

        return $this->transition($opportunity, SalesOpportunityStatus::Qualified, null, $actor);
    }

    public function closeWon(SalesOpportunity $opportunity, string $reason, User $actor): SalesOpportunity
    {
        return $this->transition($opportunity, SalesOpportunityStatus::ClosedWon, $reason, $actor);
    }

    public function closeLost(SalesOpportunity $opportunity, string $reason, User $actor): SalesOpportunity
    {
        return $this->transition($opportunity, SalesOpportunityStatus::ClosedLost, $reason, $actor);
    }

    private function transition(SalesOpportunity $opportunity, SalesOpportunityStatus $to, ?string $reason, User $actor): SalesOpportunity
    {
        return DB::transaction(function () use ($opportunity, $to, $reason, $actor): SalesOpportunity {
            $from = $opportunity->status;
            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }

            if ($to->isTerminal() && mb_trim((string) $reason) === '') {
                throw ValidationException::withMessages(['close_reason' => 'A close reason is required.']);
            }

            $opportunity->update([
                'status' => $to,
                'close_reason' => $to->isTerminal() ? mb_trim((string) $reason) : null,
                'closed_at' => $to->isTerminal() ? now() : null,
            ]);

            activity()
                ->performedOn($opportunity)
                ->causedBy($actor)
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'reason' => $reason])
                ->log('opportunity.stage_changed');

            return $opportunity->refresh();
        });
    }

    private function lead(mixed $id): ?Lead
    {
        $leadId = $this->nullableId($id);

        return $leadId === null ? null : Lead::query()->findOrFail($leadId);
    }

    private function nullableId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value < 0) {
            throw ValidationException::withMessages(['estimated_value' => 'Estimated value must be zero or greater.']);
        }

        return round((float) $value, 2);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
