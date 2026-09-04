<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Data\Crm\LeadData;
use App\Enums\LeadDisqualificationReason;
use App\Enums\LeadStatus;
use App\Models\CustomerProfile;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\LeadStageTransition;
use App\Models\User;
use App\Services\Sales\DocumentNumberGenerator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class LeadService
{
    public function __construct(
        private DocumentNumberGenerator $numbers,
    ) {}

    public function create(LeadData $data, User $actor): Lead
    {
        Gate::forUser($actor)->authorize('create', Lead::class);

        return DB::transaction(function () use ($data, $actor): Lead {
            $this->assertEmailAvailable($data->email);

            $lead = new Lead($data->toArray());
            $lead->forceFill([
                'lead_number' => $this->numbers->next(Lead::withTrashed(), 'lead_number', 'LEAD-'),
                'status' => LeadStatus::New,
                'created_by' => $actor->getKey(),
            ])->save();

            LeadStageTransition::query()->create([
                'lead_id' => $lead->getKey(),
                'from_status' => null,
                'to_status' => LeadStatus::New,
                'reason' => 'Lead captured',
                'actor_id' => $actor->getKey(),
            ]);

            activity()->performedOn($lead)->causedBy($actor)
                ->withChanges(['attributes' => $lead->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('crm.lead.created');

            return $lead->refresh();
        }, attempts: 5);
    }

    public function update(Lead $lead, LeadData $data, User $actor): Lead
    {
        Gate::forUser($actor)->authorize('update', $lead);

        if ($lead->status->isTerminal()) {
            throw new DomainException('A converted or disqualified lead is immutable.');
        }

        return DB::transaction(function () use ($lead, $data, $actor): Lead {
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->sole();
            $this->assertEmailAvailable($data->email, (int) $locked->getKey());
            $before = $locked->getAttributes();
            $locked->fill($data->toArray())->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges(['old' => $before, 'attributes' => $locked->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('crm.lead.updated');

            return $locked->refresh();
        });
    }

    public function assign(Lead $lead, ?User $assignee, User $actor): Lead
    {
        Gate::forUser($actor)->authorize('assign', $lead);

        if ($lead->status->isTerminal()) {
            throw new DomainException('A terminal lead cannot be reassigned.');
        }

        $old = $lead->assigned_to;
        $lead->forceFill(['assigned_to' => $assignee?->getKey()])->save();

        activity()->performedOn($lead)->causedBy($actor)
            ->withChanges(['old' => ['assigned_to' => $old], 'attributes' => ['assigned_to' => $lead->assigned_to]])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('crm.lead.assigned');

        return $lead->refresh();
    }

    public function transition(Lead $lead, LeadStatus $to, Interaction $interaction, User $actor, ?string $reason = null): Lead
    {
        Gate::forUser($actor)->authorize('update', $lead);

        if (in_array($to, [LeadStatus::Converted, LeadStatus::Disqualified], true)) {
            throw new DomainException('Use the conversion or disqualification workflow for terminal lead states.');
        }

        return $this->transitionWithEvidence($lead, $to, $interaction, $actor, $reason);
    }

    public function disqualify(
        Lead $lead,
        LeadDisqualificationReason $reason,
        Interaction $interaction,
        User $actor,
        ?string $note = null,
    ): Lead {
        Gate::forUser($actor)->authorize('update', $lead);

        return DB::transaction(function () use ($lead, $reason, $interaction, $actor, $note): Lead {
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->sole();
            $this->assertInteractionBelongsToLead($interaction, $locked);

            if (! $locked->status->canTransitionTo(LeadStatus::Disqualified)) {
                throw new DomainException('The lead cannot be disqualified from its current state.');
            }

            $from = $locked->status;
            $locked->forceFill([
                'status' => LeadStatus::Disqualified,
                'disqualified_reason' => $reason,
                'disqualified_note' => $note,
            ])->save();

            $this->recordTransition($locked, $from, LeadStatus::Disqualified, $interaction, $actor, $reason->value);
            $this->auditTransition($locked, $from, LeadStatus::Disqualified, $actor);

            return $locked->refresh();
        });
    }

    private function transitionWithEvidence(
        Lead $lead,
        LeadStatus $to,
        Interaction $interaction,
        User $actor,
        ?string $reason,
    ): Lead {
        return DB::transaction(function () use ($lead, $to, $interaction, $actor, $reason): Lead {
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->sole();
            $this->assertInteractionBelongsToLead($interaction, $locked);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw new DomainException(sprintf('Lead cannot transition from %s to %s.', $from->value, $to->value));
            }

            $locked->forceFill(['status' => $to])->save();
            $this->recordTransition($locked, $from, $to, $interaction, $actor, $reason);
            $this->auditTransition($locked, $from, $to, $actor);

            return $locked->refresh();
        });
    }

    private function recordTransition(
        Lead $lead,
        LeadStatus $from,
        LeadStatus $to,
        Interaction $interaction,
        User $actor,
        ?string $reason,
    ): void {
        LeadStageTransition::query()->create([
            'lead_id' => $lead->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'interaction_id' => $interaction->getKey(),
            'reason' => $reason,
            'actor_id' => $actor->getKey(),
        ]);
    }

    private function auditTransition(Lead $lead, LeadStatus $from, LeadStatus $to, User $actor): void
    {
        activity()->performedOn($lead)->causedBy($actor)
            ->withChanges(['old' => ['status' => $from->value], 'attributes' => ['status' => $to->value]])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('crm.lead.transitioned');
    }

    private function assertInteractionBelongsToLead(Interaction $interaction, Lead $lead): void
    {
        if ($interaction->subject_type !== $lead->getMorphClass()
            || (int) $interaction->subject_id !== (int) $lead->getKey()) {
            throw new DomainException('A lead stage transition requires an interaction recorded against that lead.');
        }
    }

    private function assertEmailAvailable(?string $email, ?int $ignoreLeadId = null): void
    {
        $normalized = is_string($email) ? mb_strtolower(mb_trim($email)) : '';

        if ($normalized === '') {
            return;
        }

        if (CustomerProfile::withTrashed()->whereRaw('LOWER(email) = ?', [$normalized])->exists()) {
            throw new DomainException('This email already belongs to a customer. Record the enquiry as a customer interaction instead.');
        }

        $duplicate = Lead::withTrashed()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->when($ignoreLeadId !== null, fn ($query) => $query->whereKeyNot($ignoreLeadId))
            ->exists();

        if ($duplicate) {
            throw new DomainException('A lead with this email already exists.');
        }
    }
}
