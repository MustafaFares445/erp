<?php

declare(strict_types=1);

namespace App\Enums;

enum VisitStatus: string
{
    case Scheduled = 'scheduled';
    case EnRoute = 'en_route';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case UnableToComplete = 'unable_to_complete';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::EnRoute, self::UnableToComplete, self::Cancelled],
            self::EnRoute => [self::InProgress, self::UnableToComplete, self::Cancelled],
            self::InProgress => [self::Completed, self::UnableToComplete, self::Cancelled],
            self::Completed, self::UnableToComplete, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => __('Scheduled'),
            self::EnRoute => __('En route'),
            self::InProgress => __('In progress'),
            self::Completed => __('Completed'),
            self::UnableToComplete => __('Unable to complete'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'gray',
            self::EnRoute => 'info',
            self::InProgress => 'primary',
            self::Completed => 'success',
            self::UnableToComplete => 'warning',
            self::Cancelled => 'danger',
        };
    }
}
