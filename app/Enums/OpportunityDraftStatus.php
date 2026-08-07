<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\SalesOpportunityDraft;

/**
 * Lifecycle status of a {@see SalesOpportunityDraft}
 * (contracts/plan-lifecycle.md). `Approved` and `Rejected` are terminal —
 * a superseded decision means creating a new draft, so no decision is ever
 * silently rewritten (FR-054).
 */
enum OpportunityDraftStatus: string
{
    case Draft = 'Draft';
    case Approved = 'Approved';
    case Rejected = 'Rejected';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Approved, self::Rejected],
            self::Approved, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
