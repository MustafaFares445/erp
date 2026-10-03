<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum PurchaseAgreementStatus: string implements HasLabel
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
}
