<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\EmployeeInteractionRequest;
use App\Http\Requests\Api\V1\EmployeeLeadRequest;
use App\Http\Requests\Api\V1\EmployeeOpportunityRequest;
use App\Http\Requests\Api\V1\EmployeeVanSaleRequest;
use App\Http\Requests\Api\V1\EmployeeVisitCheckInRequest;
use App\Http\Requests\Api\V1\EmployeeVisitCheckOutRequest;
use App\Http\Requests\Api\V1\EmployeeVoiceNoteRequest;
use App\Http\Resources\Api\V1\ApiDataResource;
use App\Services\Channels\ChannelActorResolver;
use App\Services\Channels\EmployeeChannelService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use LogicException;

final readonly class EmployeeChannelController
{
    public function __construct(
        private EmployeeChannelService $channel,
        private ChannelActorResolver $actors,
    ) {}

    public function tasks(Request $request): AnonymousResourceCollection
    {
        return ApiDataResource::collection($this->channel->tasks($this->actors->employee($request)));
    }

    public function visits(Request $request): AnonymousResourceCollection
    {
        return ApiDataResource::collection($this->channel->visits($this->actors->employee($request)));
    }

    public function checkIn(EmployeeVisitCheckInRequest $request, int $visit): ApiDataResource
    {
        $data = $request->validated();

        return new ApiDataResource($this->channel->checkIn(
            $this->actors->employee($request),
            $visit,
            (float) $data['latitude'],
            (float) $data['longitude'],
        ));
    }

    public function checkOut(EmployeeVisitCheckOutRequest $request, int $visit): ApiDataResource
    {
        $data = $request->validated();

        return new ApiDataResource($this->channel->checkOut(
            $this->actors->employee($request),
            $visit,
            (float) $data['latitude'],
            (float) $data['longitude'],
            is_string($data['outcome'] ?? null) ? $data['outcome'] : null,
        ));
    }

    public function voiceNote(EmployeeVoiceNoteRequest $request, int $visit): ApiDataResource
    {
        $data = $request->validated();
        $audio = $request->file('audio');

        if ($audio === null) {
            throw new LogicException('Validated employee voice-note audio is required.');
        }

        return new ApiDataResource($this->channel->voiceNote(
            $this->actors->employee($request),
            $visit,
            $audio,
            is_string($data['language'] ?? null) ? $data['language'] : null,
            is_numeric($data['duration_seconds'] ?? null) ? (int) $data['duration_seconds'] : null,
        ));
    }

    public function createLead(EmployeeLeadRequest $request): ApiDataResource
    {
        return new ApiDataResource($this->channel->createLead(
            $this->actors->employee($request),
            $request->validated(),
        ));
    }

    public function createInteraction(EmployeeInteractionRequest $request): ApiDataResource
    {
        return new ApiDataResource($this->channel->createInteraction(
            $this->actors->employee($request),
            $request->validated(),
        ));
    }

    public function createOpportunity(EmployeeOpportunityRequest $request): ApiDataResource
    {
        return new ApiDataResource($this->channel->createOpportunity(
            $this->actors->employee($request),
            $request->validated(),
        ));
    }

    public function createVanSale(EmployeeVanSaleRequest $request): ApiDataResource
    {
        return new ApiDataResource($this->channel->createVanSale(
            $this->actors->employee($request),
            $request->validated(),
        ));
    }
}
