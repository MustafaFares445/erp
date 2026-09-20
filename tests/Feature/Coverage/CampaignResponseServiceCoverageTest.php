<?php

declare(strict_types=1);

use App\Data\Crm\LeadData;
use App\Enums\CampaignChannel;
use App\Enums\CampaignResponseType;
use App\Enums\LeadSource;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignResponse;
use App\Models\CustomerProfile;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\User;
use App\Services\Crm\CampaignResponseService;
use App\Services\Crm\LeadService;
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

it('records lead interest and backfills the campaign id on the lead', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->admin()->create();
    $campaign = new Campaign;
    $campaign->forceFill([
        'campaign_number' => 'CMP-LEAD-COVERAGE',
        'name' => 'Lead response coverage',
        'channel' => CampaignChannel::Email,
        'created_by' => $actor->getKey(),
        'segment_criteria' => [],
    ])->save();

    $lead = app(LeadService::class)->create(new LeadData(
        source: LeadSource::Website,
        firstName: 'Campaign',
        email: 'campaign-lead@example.test',
    ), $actor);

    $recipient = CampaignRecipient::query()->create([
        'campaign_id' => $campaign->getKey(),
        'recipient_type' => Lead::class,
        'recipient_id' => $lead->getKey(),
        'email' => $lead->email,
        'phone' => $lead->phone,
    ]);

    $response = app(CampaignResponseService::class)->record(
        $recipient,
        CampaignResponseType::Interested,
        ['notes' => 'Interested lead'],
        $actor,
    );

    expect($response->created_lead_id)->toBe($lead->getKey())
        ->and($lead->refresh()->campaign_id)->toBe($campaign->getKey());
});

it('skips suppression when an addressable campaign recipient has a blank address', function (): void {
    Gate::before(static fn (): bool => true);

    [$actor, , , $recipient] = createCampaignResponseCoverageRecipient(CampaignChannel::Sms);
    $recipient->forceFill(['phone' => '   '])->save();

    app(CampaignResponseService::class)->record(
        $recipient,
        CampaignResponseType::Unsubscribed,
        [],
        $actor,
    );

    expect(DB::table('communication_suppressions')->count())->toBe(0);
});
