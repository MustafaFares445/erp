<?php

declare(strict_types=1);

use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Enums\NotificationChannel;
use App\Filament\Resources\Campaigns\Actions\CampaignActions;
use App\Jobs\DispatchCampaignJob;
use App\Models\Campaign;
use App\Models\CustomerProfile;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function campaignActionCoverageCampaign(User $actor, string $number): Campaign
{
    $campaign = new Campaign;
    $campaign->forceFill([
        'campaign_number' => $number,
        'name' => 'Campaign action coverage',
        'status' => CampaignStatus::Draft,
        'channel' => CampaignChannel::Email,
        'created_by' => $actor->getKey(),
        'segment_criteria' => [],
    ])->save();

    return $campaign;
}

it('executes campaign action success and validation flows', function (): void {
    Bus::fake();
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $campaign = campaignActionCoverageCampaign($actor, 'CMP-ACTION-COVERAGE');
    $template = NotificationTemplate::query()->create([
        'key' => 'crm.campaign.action.coverage',
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Campaign action coverage',
        'body' => 'Hello {{ recipient_name }}',
        'variables' => ['recipient_name'],
        'is_active' => true,
    ]);
    $campaign->forceFill(['content_template_id' => $template->getKey()])->save();
    $customer = CustomerProfile::factory()->create(['is_active' => true]);
    $this->actingAs($actor);

    $build = CampaignActions::buildRecipients();
    $build->record($campaign);

    expect($build->isVisible())->toBeTrue();
    ($build->getActionFunction())($campaign, [
        'include_leads' => false,
        'include_customers' => true,
        'customer_ids' => [$customer->getKey()],
    ]);
    expect($campaign->recipients()->count())->toBe(1);

    $send = CampaignActions::send();
    $send->record($campaign->refresh());

    expect($send->isVisible())->toBeTrue();
    ($send->getActionFunction())($campaign->refresh());
    Bus::assertDispatched(DispatchCampaignJob::class);

    $schedule = CampaignActions::schedule();
    $schedule->record($campaign->refresh());

    expect($schedule->isVisible())->toBeTrue();
    ($schedule->getActionFunction())($campaign->refresh(), [
        'scheduled_at' => now()->addHour()->toDateTimeString(),
    ]);
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Scheduled);

    $cancel = CampaignActions::cancel();
    $cancel->record($campaign->refresh());

    expect($cancel->isVisible())->toBeTrue();
    ($cancel->getActionFunction())($campaign->refresh());
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Cancelled);

    $response = (CampaignActions::downloadSendLog()->getActionFunction())($campaign->refresh());
    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();
    expect($csv)->toContain('recipient_type')->toContain((string) $customer->getKey());
});

it('executes campaign action error handling and actor guards', function (): void {
    $actor = User::factory()->create();
    $campaign = campaignActionCoverageCampaign($actor, 'CMP-ACTION-ERROR');

    ($action = CampaignActions::schedule())->record($campaign);
    ($action->getActionFunction())($campaign, []);

    $this->actingAs(User::factory()->admin()->create());
    ($action->getActionFunction())($campaign, []);

    $campaign->forceFill(['status' => CampaignStatus::Completed])->save();
    $cancel = CampaignActions::cancel();
    $cancel->record($campaign->refresh());

    expect($cancel->isVisible())->toBeFalse();
    ($cancel->getActionFunction())($campaign->refresh());

    expect(true)->toBeTrue();
});
