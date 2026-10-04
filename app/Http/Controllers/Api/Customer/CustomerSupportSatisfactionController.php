<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Requests\Api\Customer\StoreTicketSatisfactionRequest;
use App\Models\CustomerProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketSatisfactionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CustomerSupportSatisfactionController
{
    public function store(
        StoreTicketSatisfactionRequest $request,
        Ticket $ticket,
        TicketSatisfactionService $service,
    ): JsonResponse {
        abort_unless((bool) config('support.csat_enabled', false), 404);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $customer = $actor->customerProfile;
        abort_unless(
            $customer instanceof CustomerProfile
            && $ticket->customer_id === $customer->getKey(),
            404,
        );

        $response = $service->submit(
            $ticket,
            $actor,
            $request->integer('rating'),
            is_string($request->validated('comment')) ? $request->validated('comment') : null,
        );

        return response()->json([
            'id' => $response->getKey(),
            'ticket_id' => $response->ticket_id,
            'rating' => $response->rating,
            'comment' => $response->comment,
            'submitted_at' => $response->submitted_at->toIso8601String(),
        ], Response::HTTP_CREATED);
    }
}
