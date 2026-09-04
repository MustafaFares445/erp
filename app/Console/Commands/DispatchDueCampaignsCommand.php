<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Jobs\DispatchCampaignJob;
use App\Models\Campaign;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('crm:campaigns:dispatch-due')]
#[Description('Queue scheduled CRM campaigns whose send time has arrived')]
final class DispatchDueCampaignsCommand extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Campaign::query()
            ->where('status', CampaignStatus::Scheduled->value)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function (Collection $campaigns) use (&$queued): void {
                foreach ($campaigns as $campaign) {
                    if (! $campaign instanceof Campaign) {
                        continue;
                    }

                    DispatchCampaignJob::dispatch((int) $campaign->getKey(), (int) $campaign->created_by);
                    $queued++;
                }
            });

        $this->components->info(sprintf('Queued %d due CRM campaign(s).', $queued));

        return self::SUCCESS;
    }
}
