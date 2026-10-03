<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum CampaignResponseType: string implements HasLabel
{
    use HasTranslatedLabel;

    case Opened = 'opened';
    case Clicked = 'clicked';
    case Replied = 'replied';
    case Interested = 'interested';
    case Unsubscribed = 'unsubscribed';
}
