<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Requests\Api\Customer\StoreTicketMessageRequest;
use App\Http\Resources\Api\Customer\TicketMessageResource;
use App\Models\CustomerProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketMessageService;
use App\Services\Support\TicketReadStateService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class SupportTicketMessageController
{
    public function index(Request $request, Ticket $ticket, TicketReadStateService $readState): AnonymousResourceCollection
    {
        $actor = $this->assertOwned($request, $ticket);

        $messages = $ticket->messages()
            ->where('is_internal_note', false)
            ->with('sender:id,name,user_type')
            ->oldest('id')
            ->paginate(50);

        $readState->markPublicConversationRead($ticket, $actor);

        return TicketMessageResource::collection($messages);
    }

    public function store(
        StoreTicketMessageRequest $request,
        Ticket $ticket,
        TicketMessageService $messages,
        TicketReadStateService $readState,
    ): Response {
        $actor = $this->assertOwned($request, $ticket);

        try {
            $message = $messages->postForCustomer(
                $ticket,
                $request->string('message')->toString(),
                $actor,
            );
        } catch (DomainException $domainException) {
            throw ValidationException::withMessages(['message' => [$domainException->getMessage()]]);
        }

        $message->load('sender:id,name,user_type');
        $readState->markPublicConversationRead($ticket, $actor);

        return new TicketMessageResource($message)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    private function assertOwned(Request $request, Ticket $ticket): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $customer = $user->customerProfile;

        abort_unless(
            $customer instanceof CustomerProfile
            && $ticket->customer_id === $customer->getKey(),
            404,
        );

        return $user;
    }
}
