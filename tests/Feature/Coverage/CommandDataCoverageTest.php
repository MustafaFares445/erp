<?php

declare(strict_types=1);

use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Jobs\DispatchCampaignJob;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('queues due scheduled campaigns from the CRM sweep', function (): void {
    Bus::fake();
    $actor = User::factory()->create();
    $campaign = new Campaign;
    $campaign->forceFill([
        'campaign_number' => 'CMP-DUE-COVERAGE',
        'name' => 'Due campaign coverage',
        'status' => CampaignStatus::Scheduled,
        'channel' => CampaignChannel::Email,
        'scheduled_at' => now()->subMinute(),
        'created_by' => $actor->getKey(),
        'segment_criteria' => [],
    ])->save();

    $this->artisan('crm:campaigns:dispatch-due')->assertSuccessful();

    Bus::assertDispatched(DispatchCampaignJob::class, static fn (DispatchCampaignJob $job): bool => true);
});
