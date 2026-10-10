<?php

declare(strict_types=1);

use App\Data\Crm\LeadData;
use App\Enums\CampaignChannel;
use App\Enums\CampaignSendStatus;
use App\Enums\CampaignStatus;
use App\Enums\LeadSource;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Filament\Resources\Campaigns\RelationManagers\CampaignRecipientsRelationManager;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Crm\LeadService;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('labels each campaign recipient by its business type and falls back to the class name', function (): void {
    $actor = User::factory()->admin()->create();
    $lead = app(LeadService::class)->create(new LeadData(
        source: LeadSource::Website,
        firstName: 'Recipient',
        lastName: 'Lead',
        email: 'recipient-lead@example.test',
    ), $actor);
    $customer = CustomerProfile::factory()->create();

    $campaign = Campaign::query()->forceCreate([
        'campaign_number' => 'CMP-RCPT-001',
        'name' => 'Recipient type labels',
        'channel' => CampaignChannel::Email,
        'status' => CampaignStatus::Draft,
        'created_by' => $actor->getKey(),
    ]);

    $recipientFor = static fn (string $type, int|string $id): CampaignRecipient => CampaignRecipient::query()->create([
        'campaign_id' => $campaign->getKey(),
        'recipient_type' => $type,
        'recipient_id' => $id,
    ]);

    $leadRecipient = $recipientFor($lead::class, $lead->getKey());
    $customerRecipient = $recipientFor($customer::class, $customer->getKey());
    $otherRecipient = $recipientFor($actor::class, $actor->getKey());

    Livewire::actingAs($actor)
        ->test(CampaignRecipientsRelationManager::class, [
            'ownerRecord' => $campaign,
            'pageClass' => ViewCampaign::class,
        ])
        ->assertCanSeeTableRecords([$leadRecipient, $customerRecipient, $otherRecipient])
        ->assertTableColumnFormattedStateSet('recipient_type', 'Lead', record: $leadRecipient)
        ->assertTableColumnFormattedStateSet('recipient_type', 'Customer', record: $customerRecipient)
        ->assertTableColumnFormattedStateSet('recipient_type', 'User', record: $otherRecipient);
});

it('shows campaign recipient failures in full, in danger colour, with phone numbers kept left-to-right', function (): void {
    // Release QA 2026-10-10: the failed send badge was blue, the send error was cut off and the phone "+971..." rendered as "...971+" in Arabic.
    $actor = User::factory()->admin()->create();
    $campaign = Campaign::query()->forceCreate([
        'campaign_number' => 'CMP-RCPT-002',
        'name' => 'Recipient failure display',
        'channel' => CampaignChannel::Email,
        'status' => CampaignStatus::Failed,
        'created_by' => $actor->getKey(),
    ]);
    $recipient = CampaignRecipient::query()->create([
        'campaign_id' => $campaign->getKey(),
        'recipient_type' => CustomerProfile::class,
        'recipient_id' => CustomerProfile::factory()->create()->getKey(),
        'phone' => '+971500100106',
        'send_status' => CampaignSendStatus::Failed,
        'send_error' => 'The notification recipient has no valid email route.',
    ]);

    Livewire::actingAs($actor)
        ->test(CampaignRecipientsRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => ViewCampaign::class])
        ->assertTableColumnExists('send_status', static fn (TextColumn $column): bool => $column->getColor($column->getState()) === 'danger', $recipient)
        ->assertSeeText('The notification recipient has no valid email route.')
        ->assertSeeHtml('<bdi dir="ltr">+971500100106</bdi>');
});

it('translates known campaign send errors in Arabic', function (): void {
    // Release QA 2026-10-10: app-generated send errors stayed English on the Arabic campaign view (needs the merged CRM Arabic keys).
    app()->setLocale('ar');
    $actor = User::factory()->admin()->create();
    $campaign = Campaign::query()->forceCreate([
        'campaign_number' => 'CMP-RCPT-003',
        'name' => 'Arabic send error',
        'channel' => CampaignChannel::Email,
        'status' => CampaignStatus::Failed,
        'created_by' => $actor->getKey(),
    ]);
    $recipient = CampaignRecipient::query()->create([
        'campaign_id' => $campaign->getKey(),
        'recipient_type' => CustomerProfile::class,
        'recipient_id' => CustomerProfile::factory()->create()->getKey(),
        'send_status' => CampaignSendStatus::Failed,
        'send_error' => 'The notification recipient has no valid email route.',
    ]);

    Livewire::actingAs($actor)
        ->test(CampaignRecipientsRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => ViewCampaign::class])
        ->assertTableColumnFormattedStateSet('send_error', 'لا يملك مستلم الإشعار عنوان بريد إلكتروني صالحًا.', record: $recipient);
});
