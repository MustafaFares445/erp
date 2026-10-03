<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum PricingTierDiscountType: string implements HasLabel
{
    use HasTranslatedLabel;

    case Percentage = 'percentage';
    case Fixed = 'fixed';
}
