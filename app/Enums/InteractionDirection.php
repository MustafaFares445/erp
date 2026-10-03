<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum InteractionDirection: string implements HasLabel
{
    use HasTranslatedLabel;

    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
