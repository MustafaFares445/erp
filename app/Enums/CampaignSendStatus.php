<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CampaignSendStatus: string implements HasColor, HasLabel
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Suppressed = 'suppressed';

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Sent => 'success',
            self::Failed => 'danger',
            self::Suppressed => 'warning',
        };
    }
}
