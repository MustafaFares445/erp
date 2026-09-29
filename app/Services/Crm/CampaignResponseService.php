<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Data\Crm\InteractionData;
use App\Data\Sales\OpportunityData;
use App\Enums\CampaignChannel;
use App\Enums\CampaignResponseType;
use App\Enums\InteractionDirection;
use App\Enums\InteractionOutcome;
use App\Enums\InteractionType;
use App\Enums\NotificationChannel;
use App\Enums\OpportunityOrigin;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignResponse;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\User;
use App\Services\Sales\OpportunityService;
use App\Services\Settings\CurrencyCatalogService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class CampaignResponseService
{
    public function __construct(
        private InteractionService $interactions,
        private OpportunityService $opportunities,
        private CurrencyCatalogService $currencies,
    ) {}

    /** @param array<string, mixed> $payload */
    public function record(
        CampaignRecipient $recipient,
        CampaignResponseType $type,
        array $payload,
        User $actor,
    ): CampaignResponse {
        Gate::forUser($actor)->authorize('update', $this->campaign($recipient));

        return DB::transaction(function () use ($recipient, $type, $payload, $actor): CampaignResponse {
            $recipient->loadMissing(['campaign', 'recipient']);
            $campaign = $this->campaign($recipient);
            $createdLeadId = null;
            $createdOpportunityId = null;

            if ($type === CampaignResponseType::Interested) {
                if ($recipient->recipient instanceof Lead) {
                    $lead = $recipient->recipient;
                    $lead->forceFill(['campaign_id' => $lead->campaign_id ?? $recipient->campaign_id])->save();
                    $createdLeadId = $lead->getKey();
                } elseif ($recipient->recipient instanceof CustomerProfile) {
                    $customer = $recipient->recipient;
                    $summary = 'Customer expressed interest in campaign '.$campaign->campaign_number;

                    $this->interactions->log(new InteractionData(
                        subject: $customer,
                        type: InteractionType::Note,
                        direction: InteractionDirection::Inbound,
                        occurredAt: now(),
                        summary: $summary,
                        outcome: InteractionOutcome::Positive,
                        notes: is_string($payload['notes'] ?? null) ? $payload['notes'] : null,
                    ), $actor);

                    $existingOpportunityId = CampaignResponse::query()
                        ->where('campaign_recipient_id', $recipient->getKey())
                        ->where('type', CampaignResponseType::Interested->value)
                        ->whereNotNull('created_opportunity_id')
                        ->value('created_opportunity_id');

                    if (is_numeric($existingOpportunityId)) {
                        $createdOpportunityId = (int) $existingOpportunityId;
                    } else {
                        $opportunity = $this->opportunities->create(new OpportunityData(
                            summary: $summary,
                            customerId: $this->modelKey($customer),
                            title: (string) $campaign->name,
                            currency: $this->currencies->defaultCode(),
                            ownerId: $this->modelKey($actor),
                            origin: OpportunityOrigin::Inbound,
                            campaignId: $this->modelKey($campaign),
                        ), $actor);
                        $createdOpportunityId = $opportunity->getKey();
                    }
                }
            }

            if ($type === CampaignResponseType::Unsubscribed) {
                $this->suppress($recipient, $campaign);
            }

            $response = CampaignResponse::query()->create([
                'campaign_recipient_id' => $recipient->getKey(),
                'type' => $type,
                'occurred_at' => now(),
                'payload' => $payload,
                'created_lead_id' => $createdLeadId,
                'created_opportunity_id' => $createdOpportunityId,
            ]);

            activity()->performedOn($response)->causedBy($actor)
                ->withChanges(['attributes' => $response->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('crm.campaign.response_recorded');

            return $response->refresh();
        });
    }

    private function suppress(CampaignRecipient $recipient, Campaign $campaign): void
    {
        $channel = match ($campaign->channel) {
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

    private function campaign(CampaignRecipient $recipient): Campaign
    {
        $campaign = $recipient->campaign;

        if (! $campaign instanceof Campaign) {
            throw new DomainException('The parent campaign no longer exists.');
        }

        return $campaign;
    }

    private function modelKey(Model $model): int
    {
        $key = $model->getKey();

        // @codeCoverageIgnoreStart
        // CRM entities use integer primary keys in the supported schema.
        if (! is_numeric($key)) {
            throw new DomainException('CRM records require an integer primary key.');
        }
        // @codeCoverageIgnoreEnd

        return (int) $key;
    }
}
