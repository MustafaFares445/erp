<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\SalesPlan;

/**
 * Lifecycle status of a {@see SalesPlan} (data-model.md §2,
 * contracts/plan-lifecycle.md). Self-transitions are rejected everywhere;
 * `Archived` is terminal — soft-delete restore returns a plan to
 * `Archived`, never to `Active`.
 */
enum SalesPlanStatus: string
{
    case Draft = 'Draft';
    case Active = 'Active';
    case Paused = 'Paused';
    case Completed = 'Completed';
    case Archived = 'Archived';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Archived],
            self::Active => [self::Paused, self::Completed],
            self::Paused => [self::Active, self::Archived],
            self::Completed => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
