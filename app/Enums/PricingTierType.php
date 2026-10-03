<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum PricingTierType: string implements HasLabel
{
    use HasTranslatedLabel;

    case General = 'general';
    case CustomerSpecific = 'customer_specific';
    case ProductScoped = 'product_scoped';
}
