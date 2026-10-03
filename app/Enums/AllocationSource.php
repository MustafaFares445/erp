<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum AllocationSource: string implements HasLabel
{
    use HasTranslatedLabel;

    case Automatic = 'automatic';
    case Manual = 'manual';
}
