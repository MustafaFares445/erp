<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\User;
use App\Services\Crm\CampaignDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DispatchCampaignJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $campaignId,
        public int $actorId,
    ) {}

    public function handle(CampaignDispatchService $service): void
    {
        $campaign = Campaign::query()->findOrFail($this->campaignId);
        $actor = User::query()->findOrFail($this->actorId);
        $service->dispatch($campaign, $actor);
    }
}
