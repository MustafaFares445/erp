<?php

declare(strict_types=1);

namespace App\Enums;

enum CampaignSendStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
}
