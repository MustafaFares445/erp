<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryReturnDisposition: string
{
    case Saleable = 'saleable';
    case Quarantine = 'quarantine';
    case Damaged = 'damaged';

    public function stockCondition(): StockCondition
    {
        return match ($this) {
            self::Saleable => StockCondition::Saleable,
            self::Quarantine => StockCondition::Quarantine,
            self::Damaged => StockCondition::Damaged,
        };
    }
}
