<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum ReplenishmentRequirementStatus: string implements HasLabel
{
    use HasTranslatedLabel;

    case Open = 'open';
    case PartiallyCovered = 'partially_covered';
    case Covered = 'covered';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}
