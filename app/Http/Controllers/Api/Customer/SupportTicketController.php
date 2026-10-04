<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Requests\Api\Customer\StoreSupportTicketRequest;
use App\Http\Resources\Api\Customer\SupportTicketResource;
use App\Models\CustomerProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketIntakeService;
use App\Services\Support\TicketReadStateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class SupportTicketController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $customer = $this->customer($request);

        $tickets = Ticket::query()
            ->where('customer_id', $customer->getKey())
            ->with($this->resourceRelations())
            ->latest('id')
            ->paginate(20);

        return SupportTicketResource::collection($tickets);
    }

    public function store(StoreSupportTicketRequest $request, TicketIntakeService $service): Response
    {
        $actor = $this->actor($request);
        $ticket = $service->createForCustomer($request->validated(), $actor);
        $ticket->load($this->resourceRelations());

        return new SupportTicketResource($ticket)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Ticket $ticket, TicketReadStateService $readState): SupportTicketResource
    {
        $this->assertOwned($request, $ticket);
        $ticket->load($this->resourceRelations());
        $readState->markPublicConversationRead($ticket, $this->actor($request));

        return new SupportTicketResource($ticket);
    }

    /** @return list<string> */
    private function resourceRelations(): array
    {
        return [
            'serializedInventoryUnit.productVariant',
            'paymentLink.providerTransaction',
            'maintenanceRecords',
            'satisfactionResponse',
            'knowledgeLinks.knowledgeArticle.category',
            'media',
        ];
    }

    private function assertOwned(Request $request, Ticket $ticket): void
    {
        abort_unless($ticket->customer_id === $this->customer($request)->getKey(), 404);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function customer(Request $request): CustomerProfile
    {
        $customer = $this->actor($request)->customerProfile;

        abort_unless($customer instanceof CustomerProfile, 403);

        return $customer;
    }
}
