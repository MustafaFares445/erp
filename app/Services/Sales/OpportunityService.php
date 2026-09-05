<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Sales\OpportunityData;
use App\Enums\OpportunityCloseReason;
use App\Enums\OpportunityOrigin;
use App\Enums\OpportunityStage;
use App\Enums\SalesOpportunityStatus;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\OpportunityStageTransition;
use App\Models\Quotation;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class OpportunityService
{
    public function create(OpportunityData $data, User $actor): SalesOpportunity
    {
        if ($data->customerId === null && $data->leadId === null) {
            throw ValidationException::withMessages(['customer_id' => 'An opportunity requires a customer or a lead.']);
        }
        if ($data->estimatedValueMinor !== null && $data->estimatedValueMinor < 0) {
            throw ValidationException::withMessages(['estimated_value_minor' => 'Estimated value cannot be negative.']);
        }
        if ($data->probabilityPercent !== null && ($data->probabilityPercent < 0 || $data->probabilityPercent > 100)) {
            throw ValidationException::withMessages(['probability_percent' => 'Probability must be between 0 and 100.']);
        }
        if ($data->origin === OpportunityOrigin::AiVoiceNote) {
            throw new DomainException('Human-created opportunities cannot claim AI voice-note origin.');
        }

        return DB::transaction(function () use ($data, $actor): SalesOpportunity {
            $lead = $data->leadId === null ? null : Lead::query()->findOrFail($data->leadId);
            $origin = $data->origin;
            if ($origin === OpportunityOrigin::Manual) {
                $origin = $data->leadId !== null ? OpportunityOrigin::Lead : OpportunityOrigin::ExistingCustomer;
            }

            $opportunity = SalesOpportunity::query()->create([
                'status' => SalesOpportunityStatus::Approved,
                'origin' => $origin,
                'customer_id' => $data->customerId,
                'lead_id' => $data->leadId,
                'title' => $data->title,
                'summary' => mb_trim($data->summary),
                'estimated_value_minor' => $data->estimatedValueMinor,
                'currency' => mb_strtoupper($data->currency),
                'expected_close_date' => $data->expectedCloseDate,
                'stage' => OpportunityStage::Qualification,
                'probability_percent' => $data->probabilityPercent,
                'owner_id' => $data->ownerId ?? (is_numeric($lead?->assigned_to) ? (int) $lead->assigned_to : $actor->getKey()),
            ]);

            activity()->performedOn($opportunity)->causedBy($actor)->withProperties(['origin' => $origin->value])->log('opportunity.created');
            return $opportunity->refresh();
        });
    }

    public function transitionStage(
        SalesOpportunity $opportunity,
        OpportunityStage $to,
        ?Interaction $interaction,
        User $actor,
        ?OpportunityCloseReason $closeReason = null,
        ?string $closeNote = null,
    ): SalesOpportunity {
        return DB::transaction(function () use ($opportunity, $to, $interaction, $actor, $closeReason, $closeNote): SalesOpportunity {
            $from = $opportunity->stage;
            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }
            if ($to === OpportunityStage::ClosedLost && (! $closeReason instanceof OpportunityCloseReason || ! $closeReason->isLostReason())) {
                throw ValidationException::withMessages(['close_reason' => 'Closing lost requires a controlled loss reason.']);
            }
            if ($to === OpportunityStage::ClosedWon && (! $closeReason instanceof OpportunityCloseReason || ! $closeReason->isWonReason())) {
                throw ValidationException::withMessages(['close_reason' => 'Closing won requires a controlled win reason.']);
            }

            $opportunity->update([
                'stage' => $to,
                'closed_at' => $to->isClosed() ? now() : null,
                'close_reason' => $to->isClosed() ? $closeReason : null,
                'close_note' => $to->isClosed() ? $closeNote : null,
            ]);
            OpportunityStageTransition::query()->create([
                'sales_opportunity_id' => $opportunity->getKey(),
                'from_stage' => $from,
                'to_stage' => $to,
                'interaction_id' => $interaction?->getKey(),
                'actor_id' => $actor->getKey(),
                'occurred_at' => now(),
            ]);
            activity()->performedOn($opportunity)->causedBy($actor)->withProperties(['from' => $from->value, 'to' => $to->value, 'interaction_id' => $interaction?->getKey(), 'close_reason' => $closeReason?->value])->log('opportunity.stage_changed');

            return $opportunity->refresh();
        });
    }

    public function closeWonFromQuotation(Quotation $quotation): ?SalesOpportunity
    {
        $opportunity = $quotation->salesOpportunity;
        if (! $opportunity instanceof SalesOpportunity || $opportunity->stage->isClosed()) { return $opportunity; }
        $actor = $quotation->decidedBy ?? $opportunity->owner;
        if (! $actor instanceof User) { return $opportunity; }

        return $this->transitionStage($opportunity, OpportunityStage::ClosedWon, null, $actor, OpportunityCloseReason::WonAsQuoted, $quotation->decision_note);
    }

    public function closeLostOnQuotationRejection(Quotation $quotation, string $reason): ?SalesOpportunity
    {
        $opportunity = $quotation->salesOpportunity;
        if (! $opportunity instanceof SalesOpportunity || $opportunity->stage->isClosed()) { return $opportunity; }
        $actor = $quotation->decidedBy ?? $opportunity->owner;
        if (! $actor instanceof User) { return $opportunity; }

        return $this->transitionStage($opportunity, OpportunityStage::ClosedLost, null, $actor, OpportunityCloseReason::Other, $reason);
    }
}
