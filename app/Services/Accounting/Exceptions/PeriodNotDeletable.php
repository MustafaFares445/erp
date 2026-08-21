<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use DomainException;

/**
 * Thrown when a fiscal period that already has journal entries is deleted
 * (FR-017).
 *
 * The database restricts the delete anyway; this turns that constraint into a
 * message an accountant can act on rather than a driver-level error.
 */
final class PeriodNotDeletable extends DomainException
{
    public static function hasEntries(string $periodName, int $entryCount): self
    {
        return new self(__('admin.accounting.errors.period_has_entries', [
            'period' => $periodName,
            'count' => $entryCount,
        ]));
    }
}
