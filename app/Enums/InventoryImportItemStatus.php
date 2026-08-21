<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryImportItemStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Applying = 'applying';
    case Applied = 'applied';
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Invalid, self::Applied, self::Rejected], true);
    }
}
