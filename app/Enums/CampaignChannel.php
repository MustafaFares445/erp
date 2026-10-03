<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum CampaignChannel: string implements HasLabel
{
    use HasTranslatedLabel;

    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Event = 'event';
    case Other = 'other';

    public function supportsDelivery(): bool
    {
        return in_array($this, [self::Email, self::Sms, self::Whatsapp], true);
    }
}
