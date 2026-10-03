<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum QuarantineDisposition: string implements HasLabel
{
    use HasTranslatedLabel;

    case ReleaseToSaleable = 'release_to_saleable';
    case DowngradeToDamaged = 'downgrade_to_damaged';
    case Dispose = 'dispose';
    case ReturnToSupplier = 'return_to_supplier';

    public function conditionTo(): StockCondition
    {
        return match ($this) {
            self::ReleaseToSaleable, self::ReturnToSupplier => StockCondition::Saleable,
            self::DowngradeToDamaged => StockCondition::Damaged,
            self::Dispose => StockCondition::Disposed,
        };
    }

    public function requiresSupplierReturn(): bool
    {
        return $this === self::ReturnToSupplier;
    }
}
