<?php

declare(strict_types=1);

use App\Data\Crm\CampaignData;
use App\Data\Crm\LeadData;
use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\NotificationChannel;
use App\Jobs\DispatchCampaignJob;
use App\Models\Campaign;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Crm\CampaignService;
use App\Services\Crm\LeadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function campaignCoverageLead(User $actor, string $email, LeadStatus $status = LeadStatus::New): Lead
{
    $lead = app(LeadService::class)->create(new LeadData(source: LeadSource::Website, firstName: 'Coverage', email: $email), $actor);
    $lead->forceFill(['status' => $status])->saveQuietly();

    return $lead->refresh();
}
it('creates and schedules campaigns while enforcing scheduling guards', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $service = app(CampaignService::class);

    $scheduled = $service->create(new CampaignData(
        name: 'Scheduled coverage',
        channel: CampaignChannel::Email,
        scheduledAt: now()->addHour(),
    ), $actor);
    expect($scheduled->status)->toBe(CampaignStatus::Scheduled)
        ->and($scheduled->scheduled_at)->not->toBeNull();

    expect(fn () => $service->schedule($scheduled, now()->addHours(2), $actor))
        ->toThrow(DomainException::class, 'Only a draft campaign can be scheduled.');

    $draft = $service->create(new CampaignData('Draft coverage', CampaignChannel::Email), $actor);
    expect(fn () => $service->schedule($draft, now()->subMinute(), $actor))
        ->toThrow(DomainException::class, 'A scheduled campaign must have a future send time.');

    $unsupported = $service->create(new CampaignData('Unsupported coverage', CampaignChannel::Event), $actor);
    expect(fn () => $service->schedule($unsupported, now()->addHour(), $actor))
        ->toThrow(DomainException::class, 'This campaign channel does not have a delivery provider.');
});

it('validates campaign content templates against the selected delivery channel', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $service = app(CampaignService::class);

    $mail = NotificationTemplate::query()->create([
        'key' => 'crm.campaign.mail',
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Campaign',
        'body' => 'Hello {{ recipient_name }}',
        'variables' => ['recipient_name'],
        'is_active' => true,
    ]);
    $sms = NotificationTemplate::query()->create([
        'key' => 'crm.campaign.sms',
        'locale' => 'en',
        'channel' => NotificationChannel::Sms,
        'subject' => null,
        'body' => 'Hello {{ recipient_name }}',
        'variables' => ['recipient_name'],
        'is_active' => true,
    ]);
    $inactive = NotificationTemplate::query()->create([
        'key' => 'crm.campaign.inactive',
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Inactive',
        'body' => 'Inactive',
        'variables' => [],
        'is_active' => false,
    ]);

    $campaign = $service->create(new CampaignData(
        name: 'Compatible template',
        channel: CampaignChannel::Email,
        contentTemplateId: (int) $mail->getKey(),
    ), $actor);

    expect($campaign->content_template_id)->toBe($mail->getKey())
        ->and(fn () => $service->create(new CampaignData(
            name: 'Mismatched template',
            channel: CampaignChannel::Email,
            contentTemplateId: (int) $sms->getKey(),
        ), $actor))->toThrow(DomainException::class, 'does not match the campaign channel')
        ->and(fn () => $service->create(new CampaignData(
            name: 'Inactive template',
            channel: CampaignChannel::Email,
            contentTemplateId: (int) $inactive->getKey(),
        ), $actor))->toThrow(DomainException::class, 'is not active');
});

it('builds filtered campaign recipients and rejects rebuilding send history', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $service = app(CampaignService::class);
    $campaign = $service->create(new CampaignData('Recipient coverage', CampaignChannel::Email), $actor);
    $lead = campaignCoverageLead($actor, 'campaign-active@example.test');
    campaignCoverageLead($actor, 'campaign-converted@example.test', LeadStatus::Converted);
    $customer = CustomerProfile::factory()->create(['is_active' => true]);
    CustomerProfile::factory()->create(['is_active' => false]);

    $rebuilt = $service->buildRecipients($campaign, [
        'include_leads' => true,
        'include_customers' => true,
        'lead_ids' => [$lead->getKey()],
        'lead_statuses' => [LeadStatus::New->value],
        'lead_sources' => [LeadSource::Website->value],
        'customer_ids' => [$customer->getKey()],
    ], $actor);

    expect($rebuilt->recipients_count)->toBe(2);
    $recipient = $rebuilt->recipients()->firstOrFail();
    $recipient->forceFill(['send_status' => 'sent'])->save();

    expect(fn () => $service->buildRecipients($rebuilt, [], $actor))
        ->toThrow(DomainException::class, 'A campaign with send history cannot rebuild its recipient list.');

    $rebuilt->forceFill(['status' => CampaignStatus::Sending])->saveQuietly();
    expect(fn () => $service->buildRecipients($rebuilt->refresh(), [], $actor))
        ->toThrow(DomainException::class, 'Recipients can only be rebuilt before campaign sending starts.');
});
it('queues populated campaigns and rejects invalid send states', function (): void {
    Gate::before(static fn (): bool => true);
    Queue::fake();
    $actor = User::factory()->admin()->create();
    $service = app(CampaignService::class);
    $empty = $service->create(new CampaignData('Empty coverage', CampaignChannel::Email), $actor);

    expect(fn () => $service->queueSend($empty, $actor))
        ->toThrow(DomainException::class, 'Build a campaign recipient list before sending.');

    $unsupported = $service->create(new CampaignData('Unsupported send', CampaignChannel::Event), $actor);
    expect(fn () => $service->queueSend($unsupported, $actor))
        ->toThrow(DomainException::class, 'This campaign channel does not have a delivery provider.');

    $future = $service->create(new CampaignData(
        name: 'Future scheduled send',
        channel: CampaignChannel::Email,
        scheduledAt: now()->addHour(),
    ), $actor);
    expect(fn () => $service->queueSend($future, $actor))
        ->toThrow(DomainException::class, 'A scheduled campaign cannot be sent before its scheduled time.');

    $customer = CustomerProfile::factory()->create();
    $populated = $service->buildRecipients($empty, [
        'include_leads' => false,
        'include_customers' => true,
        'customer_ids' => [$customer->getKey()],
    ], $actor);
    expect($service->queueSend($populated, $actor))->toBeInstanceOf(Campaign::class);
    Queue::assertPushed(DispatchCampaignJob::class);

    $populated->forceFill(['status' => CampaignStatus::Completed])->saveQuietly();
    expect(fn () => $service->queueSend($populated->refresh(), $actor))
        ->toThrow(DomainException::class, 'Only a draft or scheduled campaign can be queued for sending.');
});
it('cancels cancellable campaigns and rejects terminal cancellation', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $service = app(CampaignService::class);
    $campaign = $service->create(new CampaignData('Cancel coverage', CampaignChannel::Email), $actor);

    $cancelled = $service->cancel($campaign, $actor);
    expect($cancelled->status)->toBe(CampaignStatus::Cancelled)
        ->and(CampaignStatus::Failed->isTerminal())->toBeTrue()
        ->and(CampaignStatus::Sending->canTransitionTo(CampaignStatus::Failed))->toBeTrue();
    expect(fn () => $service->cancel($cancelled, $actor))
        ->toThrow(DomainException::class, 'This campaign can no longer be cancelled.');
});

it('rejects nonnumeric campaign model keys', function (): void {
    $service = app(CampaignService::class);
    $method = new ReflectionMethod($service, 'modelKey');

    $record = new class extends Model
    {
        protected $keyType = 'string';

        public $incrementing = false;
    };
    $record->setAttribute($record->getKeyName(), 'bad-key');

    expect(fn (): mixed => $method->invoke($service, $record))
        ->toThrow(DomainException::class, 'CRM records require an integer primary key.');
});
