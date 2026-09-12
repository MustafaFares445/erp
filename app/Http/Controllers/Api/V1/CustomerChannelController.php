<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\CustomerQuotationRequest;
use App\Http\Requests\Api\V1\CustomerTicketRequest;
use App\Http\Requests\Api\V1\StatementRequest;
use App\Http\Resources\Api\V1\ApiDataResource;
use App\Services\Channels\ChannelActorResolver;
use App\Services\Channels\CustomerChannelService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class CustomerChannelController
{
    public function __construct(
        private CustomerChannelService $channel,
        private ChannelActorResolver $actors,
    ) {}

    public function catalog(Request $request): AnonymousResourceCollection
    {
        return ApiDataResource::collection($this->channel->catalog($this->actors->customer($request)));
    }

    public function quotations(Request $request): AnonymousResourceCollection
    {
        return ApiDataResource::collection($this->channel->quotations($this->actors->customer($request)));
    }

    public function requestQuotation(CustomerQuotationRequest $request): ApiDataResource
    {
        return new ApiDataResource($this->channel->requestQuotation(
            $this->actors->customer($request),
            $request->validated(),
        ));
    }

    public function orders(Request $request): AnonymousResourceCollection
    {
        return ApiDataResource::collection($this->channel->orders($this->actors->customer($request)));
    }

    public function order(Request $request, int $order): ApiDataResource
    {
        return new ApiDataResource($this->channel->order($this->actors->customer($request), $order));
    }

    public function invoices(Request $request): AnonymousResourceCollection
    {
        return ApiDataResource::collection($this->channel->invoices($this->actors->customer($request)));
    }

    public function invoice(Request $request, int $invoice): ApiDataResource
    {
        return new ApiDataResource($this->channel->invoice($this->actors->customer($request), $invoice));
    }

    public function downloadDocument(Request $request, string $type, int $document): Response
    {
        $download = $this->channel->document($this->actors->customer($request), $type, $document);

        return response($download['content'], 200, [
            'Content-Type' => $download['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$download['filename'].'"',
        ]);
    }

    public function statement(StatementRequest $request): ApiDataResource
    {
        $data = $request->validated();

        return new ApiDataResource($this->channel->statement(
            $this->actors->customer($request),
            is_string($data['from'] ?? null) ? $data['from'] : null,
            is_string($data['to'] ?? null) ? $data['to'] : null,
        ));
    }

    public function tickets(Request $request): AnonymousResourceCollection
    {
        return ApiDataResource::collection($this->channel->tickets($this->actors->customer($request)));
    }

    public function ticket(Request $request, int $ticket): ApiDataResource
    {
        return new ApiDataResource($this->channel->ticket($this->actors->customer($request), $ticket));
    }

    public function createTicket(CustomerTicketRequest $request): ApiDataResource
    {
        return new ApiDataResource($this->channel->createTicket(
            $this->actors->customer($request),
            $request->validated(),
        ));
    }
}
