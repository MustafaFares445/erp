<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\IssueTokenRequest;
use App\Http\Resources\Api\V1\ApiDataResource;
use App\Services\Channels\ApiTokenService;
use App\Services\Channels\ChannelActorResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class AuthTokenController
{
    public function __construct(
        private ApiTokenService $tokens,
        private ChannelActorResolver $actors,
    ) {}

    public function store(IssueTokenRequest $request): ApiDataResource
    {
        $data = $request->validated();

        return new ApiDataResource($this->tokens->issue(
            (string) $data['identifier'],
            (string) $data['password'],
            (string) $data['device_name'],
        ));
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->tokens->revokeCurrent($this->actors->authenticated($request));

        return response()->json(['message' => 'Token revoked.']);
    }
}
