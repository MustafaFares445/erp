<?php

declare(strict_types=1);

namespace App\Enums;

enum OpportunityStage: string
{
    case Qualification = 'qualification';
    case NeedsAnalysis = 'needs_analysis';
    case Demo = 'demo';
    case Proposal = 'proposal';
    case Negotiation = 'negotiation';
    case ClosedWon = 'closed_won';
    case ClosedLost = 'closed_lost';

    public function isClosed(): bool
    {
        return in_array($this, [self::ClosedWon, self::ClosedLost], true);
    }

    public function canTransitionTo(self $to): bool
    {
        if ($this->isClosed() || $this === $to) {
            return false;
        }

        if ($to->isClosed()) {
            return true;
        }

        return true;
    }

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->headline()->toString();
    }
}
