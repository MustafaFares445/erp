<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerSupportStage: string
{
    case Received = 'received';
    case UnderReview = 'under_review';
    case ActionRequired = 'action_required';
    case InProgress = 'in_progress';
    case QualityCheck = 'quality_check';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Received => __('Received'),
            self::UnderReview => __('Under review'),
            self::ActionRequired => __('Action required'),
            self::InProgress => __('In progress'),
            self::QualityCheck => __('Quality check'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
