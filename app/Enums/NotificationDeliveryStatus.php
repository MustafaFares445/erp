<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationDeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Bounced = 'bounced';
}
