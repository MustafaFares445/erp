<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Data\Crm\InteractionData;
use App\Enums\CampaignChannel;
use App\Enums\CampaignResponseType;
use App\Enums\InteractionDirection;
use App\Enums\InteractionOutcome;
use App\Enums\InteractionType;
use App\Enums\NotificationChannel;
use App\Models\CampaignRecipient;
use App\Models\CampaignResponse;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class CampaignResponseService
{
    public function __construct(private InteractionService $interactions) {}

    /** @param array<string, mixed> $payload */
    public function record(
        CampaignRecipient $recipient,
        CampaignResponseType $type,
        array $payload,
        User $actor,
    ): CampaignResponse {
        Gate::forUser($actor)->authorize('update', $recipient->campaign);

        return DB::transaction(function () use ($recipient, $type, $payload, $actor): CampaignResponse {
            $recipient->loadMissing(['campaign', 'recipient']);
            $createdLeadId = null;

            if ($type === CampaignResponseType::Interested) {
                if ($recipient->recipient instanceof Lead) {
                    $lead = $recipient->recipient;
                    $lead->forceFill(['campaign_id' => $lead->campaign_id ?? $recipient->campaign_id])->save();
                    $createdLeadId = $lead->getKey();
                } elseif ($recipient->recipient instanceof CustomerProfile) {
                    $this->interactions->log(new InteractionData(
                        subject: $recipient->recipient,
                        type: InteractionType::Note,
                        direction: InteractionDirection::Inbound,
                        occurredAt: now(),
                        summary: 'Customer expressed interest in campaign '.$recipient->campaign->campaign_number,
                        outcome: InteractionOutcome::Positive,
                        notes: is_string($payload['notes'] ?? null) ? $payload['notes'] : null,
                    ), $actor);
                }
            }

            if ($type === CampaignResponseType::Unsubscribed) {
                $this->suppress($recipient);
            }

            $response = CampaignResponse::query()->create([
                'campaign_recipient_id' => $recipient->getKey(),
                'type' => $type,
                'occurred_at' => now(),
                'payload' => $payload,
                'created_lead_id' => $createdLeadId,
            ]);

            activity()->performedOn($response)->causedBy($actor)
                ->withChanges(['attributes' => $response->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('crm.campaign.response_recorded');

            return $response->refresh();
        });
    }

    private function suppress(CampaignRecipient $recipient): void
    {
        $channel = match ($recipient->campaign->channel) {
            CampaignChannel::Email => NotificationChannel::Mail,
            CampaignChannel::Sms => NotificationChannel::Sms,
            CampaignChannel::Whatsapp => NotificationChannel::Whatsapp,
            CampaignChannel::Event, CampaignChannel::Other => null,
        };

        if (! $channel instanceof NotificationChannel) {
            return;
        }

        $address = $channel === NotificationChannel::Mail ? $recipient->email : $recipient->phone;
        if (! is_string($address) || mb_trim($address) === '') {
            return;
        }

        DB::table('communication_suppressions')->updateOrInsert([
            'channel' => $channel->value,
            'address' => mb_strtolower(mb_trim($address)),
        ], [
            'reason' => 'unsubscribed',
            'suppressed_at' => now(),
            'source_campaign_id' => $recipient->campaign_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
