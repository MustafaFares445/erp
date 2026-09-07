<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Converted = 'converted';
    case Disqualified = 'disqualified';

    public function canTransitionTo(self $target): bool
    {
        if ($target === self::Disqualified) {
            return ! $this->isTerminal();
        }

        return match ($this) {
            self::New => $target === self::Contacted,
            self::Contacted => $target === self::Qualified,
            self::Qualified => $target === self::Converted,
            self::Converted, self::Disqualified => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Converted, self::Disqualified], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'gray',
            self::Contacted => 'info',
            self::Qualified => 'warning',
            self::Converted => 'success',
            self::Disqualified => 'danger',
        };
    }
}
