<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use DomainException;

/**
 * Thrown when a posting or a reversal would land inside a closed fiscal period
 * (FR-023).
 *
 * A reversal is an ordinary posting and is validated against its own resolved
 * period, not the original entry's, so a correction can be made into a later
 * open period but never back into a closed one.
 */
final class ClosedFiscalPeriod extends DomainException
{
    public static function named(string $periodName): self
    {
        return new self(__('admin.accounting.errors.closed_period', ['period' => $periodName]));
    }
}
