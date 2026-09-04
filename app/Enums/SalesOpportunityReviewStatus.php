<?php

declare(strict_types=1);

namespace App\Enums;

enum SalesOpportunityReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NotRequired = 'not_required';

    public function isReviewable(): bool
    {
        return $this === self::Pending;
    }

    public function canTransitionTo(self $to): bool
    {
        return $this === self::Pending && in_array($to, [self::Approved, self::Rejected], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::NotRequired => 'Not required',
        };
    }
}
