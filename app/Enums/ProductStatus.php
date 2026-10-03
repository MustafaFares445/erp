<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum ProductStatus: string implements HasLabel
{
    use HasTranslatedLabel;

    case Active = 'active';
    case Inactive = 'inactive';
    case ComingSoon = 'coming_soon';

    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
