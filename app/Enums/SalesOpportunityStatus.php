<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Human review state for AI-originated opportunities. Manual opportunities
 * enter Approved because the review gate exists to police AI output.
 */
enum SalesOpportunityStatus: string
{
    case Draft = 'Draft';
    case Approved = 'Approved';
    case Rejected = 'Rejected';

    public function canTransitionTo(self $to): bool
    {
        return $this === self::Draft && in_array($to, [self::Approved, self::Rejected], true);
    }

    public function label(): string
    {
        return $this->value;
    }
}
