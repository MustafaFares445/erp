<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum PricingTierVisibility: string implements HasLabel
{
    use HasTranslatedLabel;

    case Public = 'public';
    case Restricted = 'restricted';
}
