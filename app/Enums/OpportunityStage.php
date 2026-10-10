<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OpportunityStage: string implements HasColor, HasLabel
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
        return __(str($this->value)->replace('_', ' ')->headline()->toString());
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Qualification => 'gray',
            self::NeedsAnalysis, self::Demo, self::Proposal => 'info',
            self::Negotiation => 'warning',
            self::ClosedWon => 'success',
            self::ClosedLost => 'danger',
        };
    }
}
