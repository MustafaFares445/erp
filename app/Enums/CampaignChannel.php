<?php

declare(strict_types=1);

namespace App\Enums;

enum CampaignChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Event = 'event';
    case Other = 'other';
}
