<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use DomainException;

/**
 * Thrown when a fiscal period's dates overlap an existing period's (FR-015).
 *
 * Overlapping periods would let an entry's date resolve to more than one period,
 * so "which period does this posting belong to?" must have a unique answer by
 * construction rather than by tie-break.
 */
final class OverlappingFiscalPeriod extends DomainException
{
    public static function with(string $periodName): self
    {
        return new self(__('admin.accounting.errors.overlapping_period', ['period' => $periodName]));
    }
}
