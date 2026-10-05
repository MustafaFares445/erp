<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\NotificationEventKey;
use App\Listeners\SendBusinessNotification;
use App\Models\InventoryLot;
use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A product quality milestone: a complaint was created (subject is the
 * {@see Ticket}) or a lot reached the complaint threshold (subject is the
 * {@see InventoryLot}). Recipients are resolved by {@see SendBusinessNotification}.
 */
final class SupportQualityMilestone
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public NotificationEventKey $key,
        public Ticket|InventoryLot $subject,
    ) {}
}
