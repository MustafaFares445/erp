<?php

declare(strict_types=1);

namespace App\Data\Crm;

use App\Enums\CampaignChannel;
use Illuminate\Support\Carbon;

final readonly class CampaignData
{
    /** @param array<string, mixed> $segmentCriteria */
    public function __construct(
        public string $name,
        public CampaignChannel $channel,
        public ?int $contentTemplateId = null,
        public ?Carbon $scheduledAt = null,
        public array $segmentCriteria = [],
    ) {}
}
