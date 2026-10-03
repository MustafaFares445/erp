<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum NotificationDeliveryStatus: string implements HasLabel
{
    use HasTranslatedLabel;

    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Bounced = 'bounced';
}
