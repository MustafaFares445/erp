<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryCorrectionStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this !== self::Draft;
    }
}
