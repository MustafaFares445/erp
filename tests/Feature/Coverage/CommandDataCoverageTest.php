<?php

declare(strict_types=1);

use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Jobs\DispatchCampaignJob;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('skips a due campaign whose loaded identifier or creator is unavailable', function (string $field): void {
    Bus::fake();
    $actor = User::factory()->create();
    $first = new Campaign;
    $first->forceFill([
        'campaign_number' => 'CMP-MISSING-COVERAGE',
        'name' => 'Malformed due campaign',
        'status' => CampaignStatus::Scheduled,
        'channel' => CampaignChannel::Email,
        'scheduled_at' => now()->subMinute(),
        'created_by' => $actor->id,
        'segment_criteria' => [],
    ])->save();
    $valid = $first->replicate();
    $valid->forceFill(['campaign_number' => 'CMP-VALID-COVERAGE'])->save();
    $original = Model::getEventDispatcher();
    $dispatcher = clone $original;
    Model::setEventDispatcher($dispatcher);
    $dispatcher->listen('eloquent.retrieved: '.Campaign::class, static function (Campaign $campaign) use ($first, $field): void {
        if ($campaign->id === $first->id) {
            $campaign->setAttribute($field, null);
        }
    });

    try {
        $this->artisan('crm:campaigns:dispatch-due')->assertSuccessful();
        Bus::assertDispatchedTimes(DispatchCampaignJob::class, 1);
    } finally {
        Model::setEventDispatcher($original);
    }
})->with(['id', 'created_by']);

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
