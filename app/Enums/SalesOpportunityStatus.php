<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;

/**
 * Human review state for AI-originated opportunities. Manual opportunities
 * enter Approved because the review gate exists to police AI output.
 */
enum SalesOpportunityStatus: string implements HasColor
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
        return match ($this) {
            self::Draft => __('Pending review'),
            self::Approved => __('Accepted'),
            self::Rejected => __('Rejected'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }
}
