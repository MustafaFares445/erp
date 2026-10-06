<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum InventoryReturnDisposition: string implements HasLabel
{
    use HasTranslatedLabel;

    case Saleable = 'saleable';
    case Quarantine = 'quarantine';
    case Damaged = 'damaged';
    case SupplierReturn = 'supplier_return';

    public function stockCondition(): StockCondition
    {
        return match ($this) {
            self::Saleable => StockCondition::Saleable,
            self::Quarantine, self::SupplierReturn => StockCondition::Quarantine,
            self::Damaged => StockCondition::Damaged,
        };
    }
}
