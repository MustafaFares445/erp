<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum StockCondition: string implements HasLabel
{
    use HasTranslatedLabel;

    case Saleable = 'saleable';
    case Quarantine = 'quarantine';
    case Damaged = 'damaged';
    case Disposed = 'disposed';

    public function isMaterialized(): bool
    {
        return $this !== self::Disposed;
    }

    public function allowsReservation(): bool
    {
        return $this === self::Saleable;
    }
}
