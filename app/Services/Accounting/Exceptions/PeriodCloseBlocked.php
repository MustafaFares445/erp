<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use App\Enums\PeriodCloseCheck;
use DomainException;

/**
 * Thrown when a fiscal period fails one or more mandatory close checklist
 * items and no valid override was supplied (WP-2.5, GAP-MW-18, AC-10).
 */
final class PeriodCloseBlocked extends DomainException
{
    /** @param list<PeriodCloseCheck> $failingChecks */
    public static function withFailingChecks(array $failingChecks): self
    {
        $labels = array_map(
            static fn (PeriodCloseCheck $check): string => $check->label(),
            $failingChecks,
        );

        return new self(__('admin.accounting.errors.period_close_blocked', [
            'checks' => implode(', ', $labels),
        ]));
    }
}
