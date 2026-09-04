<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Campaign;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CampaignCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Campaign $campaign,
        public int $sentCount,
        public int $failedCount,
    ) {}
}
