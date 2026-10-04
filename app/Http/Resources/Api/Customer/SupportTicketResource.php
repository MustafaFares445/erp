<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Customer;

use App\Enums\KnowledgeArticleStatus;
use App\Enums\KnowledgeArticleVisibility;
use App\Enums\TicketKnowledgeLinkType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketKnowledgeArticle;
use App\Models\User;
use App\Services\Support\CustomerSupportStateResolver;
use App\Services\Support\TicketReadStateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** @mixin Ticket */
final class SupportTicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(Request $request): array
    {
        $ticket = $this->resource;

        if (! $ticket instanceof Ticket) {
            throw new LogicException('Expected a Ticket resource.');
        }

        $state = app(CustomerSupportStateResolver::class)->resolve($ticket);
        $user = $request->user();

        return [
            'id' => $this->getKey(),
            'ticket_number' => $this->ticket_number,
            'type' => $this->type->value,
            'customer_impact' => $this->customer_impact?->value,
            'title' => $this->title,
            'description' => $this->description,
            'stage' => $state->stage->value,
            'stage_label' => $state->stage->label(),
            'action_required' => $state->actionRequired,
            'action_required_type' => $state->actionType,
            'action_required_message' => $state->message,
            'next_expected_event' => $state->nextExpectedEvent,
            'first_response_due_at' => $this->response_due_at?->toIso8601String(),
            'resolution_due_at' => $this->resolution_due_at?->toIso8601String(),
            'first_response_at' => $this->first_response_at?->toIso8601String(),
            'equipment' => [
                'source' => $this->equipment_source?->value,
                'serialized_inventory_unit_id' => $this->serialized_inventory_unit_id,
                'name' => $this->serializedInventoryUnit?->productVariant->name ?? $this->external_equipment_name,
                'model' => $this->external_equipment_model,
                'serial_number' => $this->serializedInventoryUnit->serial_number ?? $this->external_serial_number,
            ],
            'warranty' => [
                'eligibility' => $this->warranty_status?->value,
                'expires_on' => $this->warranty_expiry_date?->toDateString(),
            ],
            'payment' => $this->paymentLink === null ? null : [
                'status' => $this->paymentLink->status->value,
                'amount' => (float) $this->paymentLink->amount,
                'currency' => $this->paymentLink->currency,
                'payment_url' => $this->paymentLink->payment_url,
            ],
            'resolution_summary' => $this->resolution_summary,
            'feedback' => [
                'eligible' => (bool) config('support.csat_enabled', false)
                    && $this->status === TicketStatus::Closed
                    && $this->satisfactionResponse === null,
                'submitted' => $this->satisfactionResponse !== null,
                'rating' => $this->satisfactionResponse?->rating,
            ],
            'shared_knowledge' => $this->relationLoaded('knowledgeLinks')
                ? $this->knowledgeLinks
                    ->filter(static fn (TicketKnowledgeArticle $link): bool => $link->link_type === TicketKnowledgeLinkType::SharedWithCustomer
                        && $link->knowledgeArticle !== null
                        && $link->knowledgeArticle->status === KnowledgeArticleStatus::Published
                        && in_array($link->knowledgeArticle->visibility->value, KnowledgeArticleVisibility::customerVisibleValues(), true))
                    ->map(static fn (TicketKnowledgeArticle $link): ?array => $link->knowledgeArticle === null ? null : [
                        'id' => $link->knowledgeArticle->getKey(),
                        'title' => $link->knowledgeArticle->title,
                        'summary' => $link->knowledgeArticle->summary,
                        'slug' => $link->knowledgeArticle->slug,
                    ])
                    ->filter()
                    ->values()
                    ->all()
                : [],
            'attachments' => $this->customerVisibleAttachments()
                ->map(fn (Media $media): array => [
                    'id' => $media->getKey(),
                    'file_name' => $media->file_name,
                    'mime_type' => $media->mime_type,
                    'size' => $media->size,
                    'download_url' => route('api.customer.support.attachments.download', [
                        'ticket' => $this->getKey(),
                        'media' => $media->getKey(),
                    ]),
                ])
                ->values()
                ->all(),
            'unread_messages' => $user instanceof User
                ? app(TicketReadStateService::class)->unreadPublicCount($ticket, $user)
                : 0,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
