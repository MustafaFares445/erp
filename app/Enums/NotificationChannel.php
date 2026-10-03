<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum NotificationChannel: string implements HasLabel
{
    use HasTranslatedLabel;

    case Mail = 'mail';
    case Database = 'database';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
}
