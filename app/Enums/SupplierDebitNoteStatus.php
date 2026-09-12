<?php

declare(strict_types=1);

namespace App\Enums;

enum SupplierDebitNoteStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Reversed = 'reversed';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => $target === self::Confirmed,
            self::Confirmed => $target === self::Reversed,
            self::Reversed => false,
        };
    }
}
