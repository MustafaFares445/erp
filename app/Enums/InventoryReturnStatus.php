<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryReturnStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Posted, self::Cancelled], true);
    }
}
