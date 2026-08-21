<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\BonusSuggestion;

/**
 * Lifecycle status of a {@see BonusSuggestion}
 * (contracts/plan-lifecycle.md). `Approved` and `Rejected` are terminal
 * (FR-064); only `Approved` suggestions are summed into `bonus_amount`.
 */
enum BonusSuggestionStatus: string
{
    case Pending = 'Pending';
    case Approved = 'Approved';
    case Rejected = 'Rejected';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Approved, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
