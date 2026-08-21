<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use DomainException;

/**
 * Thrown when an entry's date falls outside every fiscal period, so posting has
 * no period to stamp (FR-018).
 *
 * A draft is allowed to have no period — it is resolved at posting time
 * (research.md R-004) — so this surfaces only when the entry is actually
 * committed.
 */
final class NoFiscalPeriodForDate extends DomainException
{
    public static function forDate(string $entryDate): self
    {
        return new self(__('admin.accounting.errors.no_period_for_date', ['date' => $entryDate]));
    }
}
