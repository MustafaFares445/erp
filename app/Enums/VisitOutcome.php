<?php

declare(strict_types=1);

namespace App\Enums;

enum VisitOutcome: string
{
    case Successful = 'successful';
    case FollowUpRequired = 'follow_up_required';
    case CustomerUnavailable = 'customer_unavailable';
    case NotInterested = 'not_interested';
    case UnableToComplete = 'unable_to_complete';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Successful => __('Successful'),
            self::FollowUpRequired => __('Follow-up required'),
            self::CustomerUnavailable => __('Customer unavailable'),
            self::NotInterested => __('Not interested'),
            self::UnableToComplete => __('Unable to complete'),
            self::Other => __('Other'),
        };
    }
}
