<?php

declare(strict_types=1);

namespace App\Enums;

enum SalesOpportunityStatus: string
{
    case Draft = 'draft';
    case Qualified = 'qualified';
    case ClosedWon = 'closed_won';
    case ClosedLost = 'closed_lost';

    public function isTerminal(): bool
    {
        return in_array($this, [self::ClosedWon, self::ClosedLost], true);
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Draft => in_array($to, [self::Qualified, self::ClosedLost], true),
            self::Qualified => in_array($to, [self::ClosedWon, self::ClosedLost], true),
            self::ClosedWon, self::ClosedLost => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Qualified => 'Qualified',
            self::ClosedWon => 'Closed Won',
            self::ClosedLost => 'Closed Lost',
        };
    }
}
