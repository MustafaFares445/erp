<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryConditionChangeStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return $this === self::Draft
            && in_array($target, [self::Posted, self::Cancelled], true);
    }

    public function isTerminal(): bool
    {
        return $this !== self::Draft;
    }
}
