<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum ReplenishmentCoverageStatus: string implements HasLabel
{
    use HasTranslatedLabel;

    case Active = 'active';
    case Fulfilled = 'fulfilled';
    case Released = 'released';
    case Cancelled = 'cancelled';
}
