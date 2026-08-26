<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use DomainException;

/**
 * Thrown when a report's end date precedes its start date (FR-010).
 *
 * Returning an empty result for an inverted range would render as "no
 * activity in this period", which is a wrong answer presented as a fact — so
 * this is rejected before any aggregation runs, rather than producing a
 * report at all.
 */
final class InvalidReportRange extends DomainException
{
    public static function endBeforeStart(string $from, string $to): self
    {
        return new self(__('admin.accounting.errors.invalid_report_range', [
            'from' => $from,
            'to' => $to,
        ]));
    }
}
