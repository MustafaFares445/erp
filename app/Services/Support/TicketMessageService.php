<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\SupportAutomationEvent;
use App\Enums\TicketStatus;
use App\Models\CustomerProfile;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class TicketMessageService
{
    public function postForCustomer(Ticket $ticket, string $body, User $actor): TicketMessage
    {
        $customer = $actor->customerProfile;

        if (! $customer instanceof CustomerProfile || $ticket->customer_id !== $customer->getKey()) {
            throw new DomainException('This support ticket does not belong to the customer.');
        }

        if (in_array($ticket->status, [TicketStatus::Closed, TicketStatus::Cancelled], true)) {
            throw new DomainException('Messages cannot be posted to a closed or cancelled support ticket.');
        }

        return DB::transaction(function () use ($ticket, $body, $actor): TicketMessage {
            $message = TicketMessage::query()->create([
                'ticket_id' => $ticket->getKey(),
                'sender_user_id' => $actor->getKey(),
                'message' => $body,
                'is_internal_note' => false,
                'source_channel' => 'customer_app',
            ]);

            $ticket->forceFill([
                'last_public_message_at' => now(),
                'last_customer_message_at' => now(),
                'last_activity_at' => now(),
            ])->save();

            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['message_id' => $message->getKey(), 'is_internal_note' => false]])
                ->withProperties(['source_channel' => 'customer_app', 'ip_address' => request()->ip()])
                ->log('support.ticket.message_posted');

            DB::afterCommit(static fn () => app(SupportAutomationEngine::class)->handle(
                SupportAutomationEvent::TicketMessagePosted,
                $ticket->refresh(),
                ['message_id' => $message->getKey(), 'is_internal_note' => false, 'source_channel' => 'customer_app'],
            ));

            return $message;
        });
    }

    public function post(
        Ticket $ticket,
        string $body,
        bool $isInternalNote,
        User $actor,
        string $sourceChannel = 'dashboard',
    ): TicketMessage {
        Gate::forUser($actor)->authorize('message', $ticket);

        return DB::transaction(function () use ($ticket, $body, $isInternalNote, $actor, $sourceChannel): TicketMessage {
            $message = TicketMessage::query()->create([
                'ticket_id' => $ticket->getKey(),
                'sender_user_id' => $actor->getKey(),
                'message' => $body,
                'is_internal_note' => $isInternalNote,
                'source_channel' => $sourceChannel,
            ]);

            $messageTimestamp = now();
            $ticketAttributes = ['last_activity_at' => $messageTimestamp];

            if (! $isInternalNote) {
                $ticketAttributes['last_public_message_at'] = $messageTimestamp;
                $ticketAttributes['last_agent_message_at'] = $messageTimestamp;
            }

            $isFirstResponse = ! $isInternalNote && $ticket->first_response_at === null;

            if ($isFirstResponse) {
                $ticketAttributes['first_response_at'] = $messageTimestamp;
            }

            $ticket->forceFill($ticketAttributes)->save();

            if ($isFirstResponse) {
                app(SlaService::class)->completeFirstResponse($ticket->refresh());
            }

            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['message_id' => $message->getKey(), 'is_internal_note' => $isInternalNote]])
                ->withProperties([
                    'source_channel' => $sourceChannel,
                    'ip_address' => $sourceChannel === 'dashboard' ? request()->ip() : null,
                ])
                ->log('support.ticket.message_posted');

            if ($sourceChannel !== 'automation') {
                DB::afterCommit(static fn () => app(SupportAutomationEngine::class)->handle(
                    SupportAutomationEvent::TicketMessagePosted,
                    $ticket->refresh(),
                    [
                        'message_id' => $message->getKey(),
                        'is_internal_note' => $isInternalNote,
                    ],
                ));
            }

            return $message;
        });
    }
}
