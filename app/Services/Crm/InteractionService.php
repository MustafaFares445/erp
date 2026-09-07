<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Data\Crm\InteractionData;
use App\Models\CustomerProfile;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class InteractionService
{
    public function log(InteractionData $data, User $actor): Interaction
    {
        Gate::forUser($actor)->authorize('create', Interaction::class);

        if (! $data->subject instanceof Lead && ! $data->subject instanceof CustomerProfile) {
            throw new DomainException('CRM interactions may only be recorded against a lead or customer.');
        }

        return DB::transaction(function () use ($data, $actor): Interaction {
            $interaction = new Interaction([
                'type' => $data->type,
                'direction' => $data->direction,
                'outcome' => $data->outcome,
                'occurred_at' => $data->occurredAt,
                'summary' => $data->summary,
                'notes' => $data->notes,
                'employee_id' => $actor->getKey(),
                'customer_visit_id' => $data->customerVisitId,
                'ticket_id' => $data->ticketId,
            ]);
            $interaction->subject()->associate($data->subject);
            $interaction->save();

            if ($data->subject instanceof Lead
                && ($data->subject->last_interaction_at === null || $data->occurredAt->gt($data->subject->last_interaction_at))) {
                $data->subject->forceFill(['last_interaction_at' => $data->occurredAt])->save();
            }

            activity()->performedOn($interaction)->causedBy($actor)
                ->withChanges(['attributes' => $interaction->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('crm.interaction.logged');

            return $interaction->refresh();
        });
    }
}
