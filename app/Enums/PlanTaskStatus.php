<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\PlanTask;

/**
 * Lifecycle status of a {@see PlanTask}
 * (contracts/plan-lifecycle.md). `Completed -> InProgress` is a reopen: it
 * requires `employees.task.manage`, clears `completed_at`, and marks the
 * parent plan's performance score stale.
 */
enum PlanTaskStatus: string
{
    case Pending = 'Pending';
    case InProgress = 'InProgress';
    case Completed = 'Completed';
    case Cancelled = 'Cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::InProgress, self::Completed, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled, self::Pending],
            self::Completed => [self::InProgress],
            self::Cancelled => [self::Pending],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
