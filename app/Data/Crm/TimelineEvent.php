<?php

declare(strict_types=1);

namespace App\Data\Crm;

use App\Services\Crm\Timeline\TimelineSource;
use Carbon\CarbonInterface;
use Filament\Support\Icons\Heroicon;

/**
 * One row of the Customer 360 timeline (CR-05) — the rendering-ready shape
 * every {@see TimelineSource} hydrates into, so
 * the Blade stream never touches a raw enum value or a null actor again.
 */
final readonly class TimelineEvent
{
    /**
     * @param  list<array{label: string, url: string}>  $relatedLinks
     */
    public function __construct(
        public string $type,
        public int $id,
        public CarbonInterface $occurredAt,
        public bool $occurredAtIsDateOnly,
        public string $title,
        public ?string $detail,
        public ?string $statusLabel,
        public ?string $statusColor,
        public Heroicon|string $icon,
        public ?int $amountMinor,
        public ?string $currency,
        public ?string $actorName,
        public ?string $link,
        public array $relatedLinks = [],
        public bool $isSystem = false,
    ) {}
}
