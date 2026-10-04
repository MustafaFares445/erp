<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Customer;

use App\Enums\UserType;
use App\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketMessage */
final class TicketMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'message' => $this->message,
            'sender' => [
                'name' => $this->sender?->name,
                'side' => $this->sender?->user_type === UserType::Customer ? 'customer' : 'support',
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
