<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\TicketStatus;
use App\Models\CustomerProfile;
use App\Models\Ticket;
use App\Models\TicketSatisfactionResponse;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TicketSatisfactionService
{
    public function submit(
        Ticket $ticket,
        User $actor,
        int $rating,
        ?string $comment = null,
        string $sourceChannel = 'customer_app',
    ): TicketSatisfactionResponse {
        $customer = $actor->customerProfile;

        if (! $customer instanceof CustomerProfile || $ticket->customer_id !== $customer->getKey()) {
            throw new DomainException('This support ticket does not belong to the customer.');
        }

        if ($ticket->status !== TicketStatus::Closed) {
            throw ValidationException::withMessages([
                'ticket' => 'Feedback can be submitted only after the support ticket is closed.',
            ]);
        }

        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages([
                'rating' => 'The rating must be between 1 and 5.',
            ]);
        }

        return DB::transaction(function () use ($ticket, $customer, $actor, $rating, $comment, $sourceChannel): TicketSatisfactionResponse {
            $existing = TicketSatisfactionResponse::query()
                ->where('ticket_id', $ticket->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof TicketSatisfactionResponse) {
                throw ValidationException::withMessages([
                    'rating' => 'Feedback has already been submitted for this ticket.',
                ]);
            }

            $response = TicketSatisfactionResponse::query()->create([
                'ticket_id' => $ticket->getKey(),
                'customer_id' => $customer->getKey(),
                'rating' => $rating,
                'comment' => filled($comment) ? mb_trim($comment) : null,
                'submitted_at' => now(),
                'source_channel' => $sourceChannel,
            ]);

            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => $sourceChannel,
                    'rating' => $rating,
                ])
                ->log('support.csat.submitted');

            return $response;
        });
    }
}
