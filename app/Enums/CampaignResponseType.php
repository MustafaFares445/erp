<?php

declare(strict_types=1);

namespace App\Enums;

enum CampaignResponseType: string
{
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Replied = 'replied';
    case Interested = 'interested';
    case Unsubscribed = 'unsubscribed';
}
