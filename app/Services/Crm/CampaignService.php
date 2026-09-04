<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Data\Crm\CampaignData;
use App\Enums\CampaignStatus;
use App\Enums\LeadStatus;
use App\Jobs\DispatchCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\User;
use App\Services\Sales\DocumentNumberGenerator;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class CampaignService
{
    public function __construct(private DocumentNumberGenerator $numbers) {}

    public function create(CampaignData $data, User $actor): Campaign
    {
        Gate::forUser($actor)->authorize('create', Campaign::class);

        return DB::transaction(function () use ($data, $actor): Campaign {
            $campaign = new Campaign([
                'name' => $data->name,
                'channel' => $data->channel,
                'content_template_id' => $data->contentTemplateId,
                'segment_criteria' => $data->segmentCriteria,
            ]);
            $campaign->forceFill([
                'campaign_number' => $this->numbers->next(Campaign::withTrashed(), 'campaign_number', 'CMP-'),
                'status' => CampaignStatus::Draft,
                'created_by' => $actor->getKey(),
            ])->save();

            if ($data->scheduledAt instanceof Carbon) {
                $this->schedule($campaign, $data->scheduledAt, $actor);
            }

            activity()->performedOn($campaign)->causedBy($actor)
                ->withChanges(['attributes' => $campaign->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('crm.campaign.created');

            return $campaign->refresh();
        }, attempts: 5);
    }

    public function schedule(Campaign $campaign, Carbon $scheduledAt, User $actor): Campaign
    {
        Gate::forUser($actor)->authorize('update', $campaign);

        if ($campaign->status !== CampaignStatus::Draft) {
            throw new DomainException('Only a draft campaign can be scheduled.');
        }

        if ($scheduledAt->lte(now())) {
            throw new DomainException('A scheduled campaign must have a future send time.');
        }

        $campaign->forceFill([
            'status' => CampaignStatus::Scheduled,
            'scheduled_at' => $scheduledAt,
        ])->save();

        activity()->performedOn($campaign)->causedBy($actor)
            ->withChanges(['attributes' => ['status' => CampaignStatus::Scheduled->value, 'scheduled_at' => $scheduledAt->toDateTimeString()]])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('crm.campaign.scheduled');

        return $campaign->refresh();
    }

    /** @param array<string, mixed> $criteria */
    public function buildRecipients(Campaign $campaign, array $criteria, User $actor): Campaign
    {
        Gate::forUser($actor)->authorize('update', $campaign);

        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true)) {
            throw new DomainException('Recipients can only be rebuilt before campaign sending starts.');
        }

        return DB::transaction(function () use ($campaign, $criteria, $actor): Campaign {
            if ($campaign->recipients()->where('send_status', '!=', 'pending')->exists()) {
                throw new DomainException('A campaign with send history cannot rebuild its recipient list.');
            }

            $campaign->recipients()->delete();
            $campaign->forceFill(['segment_criteria' => $criteria])->save();

            if ((bool) ($criteria['include_leads'] ?? true)) {
                $this->leadRecipients($criteria)->chunkById(200, function ($leads) use ($campaign): void {
                    foreach ($leads as $lead) {
                        if ($lead instanceof Lead) {
                            $this->snapshotRecipient($campaign, $lead);
                        }
                    }
                });
            }

            if ((bool) ($criteria['include_customers'] ?? true)) {
                $this->customerRecipients($criteria)->chunkById(200, function ($customers) use ($campaign): void {
                    foreach ($customers as $customer) {
                        if ($customer instanceof CustomerProfile) {
                            $this->snapshotRecipient($campaign, $customer);
                        }
                    }
                });
            }

            activity()->performedOn($campaign)->causedBy($actor)
                ->withChanges(['attributes' => [
                    'segment_criteria' => $criteria,
                    'recipient_count' => $campaign->recipients()->count(),
                ]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('crm.campaign.recipients_built');

            return $campaign->refresh()->loadCount('recipients');
        });
    }

    public function queueSend(Campaign $campaign, User $actor): Campaign
    {
        Gate::forUser($actor)->authorize('send', $campaign);

        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true)) {
            throw new DomainException('Only a draft or scheduled campaign can be queued for sending.');
        }

        if ($campaign->recipients()->doesntExist()) {
            throw new DomainException('Build a campaign recipient list before sending.');
        }

        DispatchCampaignJob::dispatch((int) $campaign->getKey(), (int) $actor->getKey())->afterCommit();

        activity()->performedOn($campaign)->causedBy($actor)
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('crm.campaign.send_queued');

        return $campaign->refresh();
    }

    public function cancel(Campaign $campaign, User $actor): Campaign
    {
        Gate::forUser($actor)->authorize('update', $campaign);

        if (! $campaign->status->canTransitionTo(CampaignStatus::Cancelled)) {
            throw new DomainException('This campaign can no longer be cancelled.');
        }

        $from = $campaign->status;
        $campaign->forceFill(['status' => CampaignStatus::Cancelled])->save();

        activity()->performedOn($campaign)->causedBy($actor)
            ->withChanges(['old' => ['status' => $from->value], 'attributes' => ['status' => CampaignStatus::Cancelled->value]])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('crm.campaign.cancelled');

        return $campaign->refresh();
    }

    /** @param array<string, mixed> $criteria @return Builder<Lead> */
    private function leadRecipients(array $criteria): Builder
    {
        return Lead::query()
            ->whereNotIn('status', [LeadStatus::Converted->value, LeadStatus::Disqualified->value])
            ->when(is_array($criteria['lead_ids'] ?? null) && $criteria['lead_ids'] !== [], fn (Builder $query) => $query->whereKey($criteria['lead_ids']))
            ->when(is_array($criteria['lead_statuses'] ?? null) && $criteria['lead_statuses'] !== [], fn (Builder $query) => $query->whereIn('status', $criteria['lead_statuses']))
            ->when(is_array($criteria['lead_sources'] ?? null) && $criteria['lead_sources'] !== [], fn (Builder $query) => $query->whereIn('source', $criteria['lead_sources']));
    }

    /** @param array<string, mixed> $criteria @return Builder<CustomerProfile> */
    private function customerRecipients(array $criteria): Builder
    {
        return CustomerProfile::query()
            ->where('is_active', true)
            ->when(is_array($criteria['customer_ids'] ?? null) && $criteria['customer_ids'] !== [], fn (Builder $query) => $query->whereKey($criteria['customer_ids']));
    }

    private function snapshotRecipient(Campaign $campaign, Model $recipient): CampaignRecipient
    {
        return CampaignRecipient::query()->firstOrCreate([
            'campaign_id' => $campaign->getKey(),
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
        ], [
            'email' => $recipient->getAttribute('email'),
            'phone' => $recipient->getAttribute('phone'),
        ]);
    }
}
