<?php

declare(strict_types=1);

use App\Enums\CampaignChannel;
use App\Enums\CampaignResponseType;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignResponse;
use App\Models\CustomerProfile;
use App\Models\Interaction;
use App\Models\User;
use App\Services\Crm\CampaignResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function createCampaignResponseCoverageRecipient(CampaignChannel $channel): array
{
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $campaign = new Campaign;
    $campaign->forceFill([
        'campaign_number' => 'CMP-'.fake()->unique()->numerify('######'),
        'name' => 'Coverage campaign',
        'channel' => $channel,
        'created_by' => $actor->getKey(),
        'segment_criteria' => [],
    ])->save();
    $recipient = CampaignRecipient::query()->create([
        'campaign_id' => $campaign->getKey(),
        'recipient_type' => CustomerProfile::class,
        'recipient_id' => $customer->getKey(),
        'email' => $customer->email,
        'phone' => $customer->phone,
    ]);

    return [$actor, $customer, $campaign, $recipient];
}

it('records an unsubscribe response and suppresses the campaign email address', function (): void {
    Gate::before(static fn (): bool => true);
    [$actor, $customer, $campaign, $recipient] = createCampaignResponseCoverageRecipient(CampaignChannel::Email);

    $response = app(CampaignResponseService::class)->record(
        $recipient,
        CampaignResponseType::Unsubscribed,
        ['notes' => 'No more email'],
        $actor,
    );

    expect($response)->toBeInstanceOf(CampaignResponse::class)
        ->and($response->type)->toBe(CampaignResponseType::Unsubscribed)
        ->and(DB::table('communication_suppressions')
            ->where('channel', 'mail')
            ->where('address', mb_strtolower((string) $customer->email))
            ->exists())->toBeTrue();
});
it('records customer interest and logs the inbound CRM interaction', function (): void {
    Gate::before(static fn (): bool => true);
    [$actor, $customer, $campaign, $recipient] = createCampaignResponseCoverageRecipient(CampaignChannel::Other);

    $response = app(CampaignResponseService::class)->record(
        $recipient,
        CampaignResponseType::Interested,
        ['notes' => 'Please follow up'],
        $actor,
    );

    expect($response->created_lead_id)->toBeNull()
        ->and(Interaction::query()
            ->where('subject_type', CustomerProfile::class)
            ->where('subject_id', $customer->getKey())
            ->where('summary', 'Customer expressed interest in campaign '.$campaign->campaign_number)
            ->exists())->toBeTrue();
});

it('records an unsubscribe response for a non-addressable campaign channel without suppression', function (): void {
    Gate::before(static fn (): bool => true);
    [$actor, , , $recipient] = createCampaignResponseCoverageRecipient(CampaignChannel::Event);

    app(CampaignResponseService::class)->record($recipient, CampaignResponseType::Unsubscribed, [], $actor);

    expect(DB::table('communication_suppressions')->count())->toBe(0);
});
