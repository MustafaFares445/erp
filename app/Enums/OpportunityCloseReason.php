<?php

declare(strict_types=1);

namespace App\Enums;

enum OpportunityCloseReason: string
{
    case WonAsQuoted = 'won_as_quoted';
    case WonAfterNegotiation = 'won_after_negotiation';
    case LostOnPrice = 'lost_on_price';
    case LostToCompetitor = 'lost_to_competitor';
    case LostNoBudget = 'lost_no_budget';
    case LostNoDecision = 'lost_no_decision';
    case LostTimingWrong = 'lost_timing_wrong';
    case LostRequirementChanged = 'lost_requirement_changed';
    case Other = 'other';

    public function isWonReason(): bool
    {
        return in_array($this, [self::WonAsQuoted, self::WonAfterNegotiation], true);
    }

    public function isLostReason(): bool
    {
        return ! $this->isWonReason();
    }

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->headline()->toString();
    }
}
