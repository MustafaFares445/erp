<?php

declare(strict_types=1);

use App\Data\Crm\CampaignData;
use App\Data\Crm\LeadData;
use App\Enums\CampaignChannel;
use App\Enums\CampaignSendStatus;
use App\Enums\CampaignStatus;
use App\Enums\LeadSource;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Crm\CampaignDispatchService;
use App\Services\Crm\CampaignService;
use App\Services\Crm\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('sends eligible campaign recipients and records suppressed recipients without delivery', function (): void {
    Notification::fake();

    $actor = User::factory()->admin()->create();
    $leadService = app(LeadService::class);

    $eligible = $leadService->create(new LeadData(
        source: LeadSource::Website,
        firstName: 'Eligible',
        lastName: 'Lead',
        email: 'eligible@example.test',
    ), $actor);

    $suppressed = $leadService->create(new LeadData(
        source: LeadSource::Referral,
        firstName: 'Suppressed',
        lastName: 'Lead',
        email: 'suppressed@example.test',
    ), $actor);

    $template = NotificationTemplate::query()->create([
        'key' => 'crm.campaign.regression.email',
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Hello {{ recipient_name }}',
        'body' => '{{ campaign_name }} is available now.',
        'variables' => ['recipient_name', 'campaign_name'],
        'is_active' => true,
    ]);

    $campaigns = app(CampaignService::class);
    $campaign = $campaigns->create(new CampaignData(
        name: 'Regression Campaign',
        channel: CampaignChannel::Email,
        contentTemplateId: (int) $template->getKey(),
    ), $actor);

    $campaign = $campaigns->buildRecipients($campaign, [
        'include_leads' => true,
        'include_customers' => false,
        'lead_ids' => [$eligible->getKey(), $suppressed->getKey()],
    ], $actor);

    DB::table('communication_suppressions')->insert([
        'channel' => NotificationChannel::Mail->value,
        'address' => 'suppressed@example.test',
        'reason' => 'regression_test',
        'suppressed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $campaign = app(CampaignDispatchService::class)->dispatch($campaign, $actor);

    expect($campaign->status)->toBe(CampaignStatus::Completed)
        ->and($campaign->recipients()->count())->toBe(2);

    $eligibleRecipient = $campaign->recipients()
        ->where('recipient_type', $eligible->getMorphClass())
        ->where('recipient_id', $eligible->getKey())
        ->sole();
    $suppressedRecipient = $campaign->recipients()
        ->where('recipient_type', $suppressed->getMorphClass())
        ->where('recipient_id', $suppressed->getKey())
        ->sole();

    expect($eligibleRecipient->send_status)->toBe(CampaignSendStatus::Sent)
        ->and($eligibleRecipient->notificationDelivery?->status)->toBe(NotificationDeliveryStatus::Queued)
        ->and($suppressedRecipient->send_status)->toBe(CampaignSendStatus::Suppressed)
        ->and($suppressedRecipient->notificationDelivery?->status)->toBe(NotificationDeliveryStatus::Suppressed)
        ->and($suppressedRecipient->sent_at)->toBeNull();

    Notification::assertCount(1);
});
