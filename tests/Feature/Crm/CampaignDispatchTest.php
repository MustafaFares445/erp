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
use App\Models\CampaignRecipient;
use App\Models\Lead;
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

it('records campaign recipient failure reasons for skipped and invalid delivery paths', function (): void {
    Notification::fake();

    $actor = User::factory()->admin()->create();
    $lead = app(LeadService::class)->create(new LeadData(
        source: LeadSource::Website,
        firstName: 'Coverage',
        lastName: 'Lead',
        email: 'coverage-campaign@example.test',
    ), $actor);

    $mailTemplate = NotificationTemplate::query()->create([
        'key' => 'crm.campaign.coverage.mail',
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Coverage',
        'body' => 'Coverage body',
        'variables' => [],
        'is_active' => true,
    ]);

    $campaigns = app(CampaignService::class);
    $dispatcher = app(CampaignDispatchService::class);

    $skipped = $campaigns->create(new CampaignData(
        name: 'Already Sent',
        channel: CampaignChannel::Email,
        contentTemplateId: (int) $mailTemplate->getKey(),
    ), $actor);
    $skippedRecipient = $skipped->recipients()->create([
        'recipient_type' => $lead->getMorphClass(),
        'recipient_id' => $lead->getKey(),
        'email' => $lead->email,
        'send_status' => CampaignSendStatus::Sent,
    ]);
    $dispatcher->dispatch($skipped, $actor);
    expect($skippedRecipient->refresh()->send_status)->toBe(CampaignSendStatus::Sent);

    $missingTemplate = $campaigns->create(new CampaignData(
        name: 'Missing Template',
        channel: CampaignChannel::Email,
    ), $actor);
    $missingRecipient = $missingTemplate->recipients()->create([
        'recipient_type' => $lead->getMorphClass(),
        'recipient_id' => $lead->getKey(),
        'email' => $lead->email,
    ]);
    $dispatcher->dispatch($missingTemplate, $actor);
    expect($missingRecipient->refresh()->send_status)->toBe(CampaignSendStatus::Failed)
        ->and($missingRecipient->send_error)->toContain('template is missing');

    $unsupported = $campaigns->create(new CampaignData(
        name: 'Unsupported Channel',
        channel: CampaignChannel::Event,
        contentTemplateId: (int) $mailTemplate->getKey(),
    ), $actor);
    $unsupportedRecipient = $unsupported->recipients()->create([
        'recipient_type' => $lead->getMorphClass(),
        'recipient_id' => $lead->getKey(),
        'email' => $lead->email,
    ]);
    $dispatcher->dispatch($unsupported, $actor);
    expect($unsupportedRecipient->refresh()->send_status)->toBe(CampaignSendStatus::Failed)
        ->and($unsupportedRecipient->send_error)->toContain('no delivery provider');

    $mismatch = $campaigns->create(new CampaignData(
        name: 'Mismatched Template',
        channel: CampaignChannel::Sms,
        contentTemplateId: (int) $mailTemplate->getKey(),
    ), $actor);
    $mismatchRecipient = $mismatch->recipients()->create([
        'recipient_type' => $lead->getMorphClass(),
        'recipient_id' => $lead->getKey(),
        'phone' => '+971500000000',
    ]);
    $dispatcher->dispatch($mismatch, $actor);
    expect($mismatchRecipient->refresh()->send_status)->toBe(CampaignSendStatus::Failed)
        ->and($mismatchRecipient->send_error)->toContain('does not match');

    $missingModel = $campaigns->create(new CampaignData(
        name: 'Missing Recipient',
        channel: CampaignChannel::Email,
        contentTemplateId: (int) $mailTemplate->getKey(),
    ), $actor);
    $missingModelRecipient = CampaignRecipient::query()->create([
        'campaign_id' => $missingModel->getKey(),
        'recipient_type' => (new Lead)->getMorphClass(),
        'recipient_id' => 999999999,
        'email' => 'missing@example.test',
        'send_status' => CampaignSendStatus::Pending,
    ]);
    $dispatcher->dispatch($missingModel, $actor);
    expect($missingModelRecipient->refresh()->send_status)->toBe(CampaignSendStatus::Failed)
        ->and($missingModelRecipient->send_error)->toContain('no longer exists');
});
