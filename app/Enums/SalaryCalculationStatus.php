<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\EmployeeSalaryCalculation;

/**
 * Lifecycle status of an {@see EmployeeSalaryCalculation}
 * (contracts/plan-lifecycle.md). `Superseded` is terminal; a `Confirmed` row
 * moves to `Superseded` only via a fresh recalculation, never back to
 * `Draft`/`PendingConfirmation`.
 */
enum SalaryCalculationStatus: string
{
    case Draft = 'Draft';
    case PendingConfirmation = 'PendingConfirmation';
    case Confirmed = 'Confirmed';
    case Superseded = 'Superseded';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingConfirmation],
            self::PendingConfirmation => [self::Confirmed, self::Superseded],
            self::Confirmed => [self::Superseded],
            self::Superseded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
