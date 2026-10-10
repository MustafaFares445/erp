<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PurchaseAgreementStatus: string implements HasColor, HasLabel
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isUsable(): bool
    {
        return $this === self::Active;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'info',
            self::Active => 'success',
            self::Expired => 'gray',
            self::Cancelled => 'danger',
        };
    }
}
