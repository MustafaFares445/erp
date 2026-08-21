<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use DomainException;

/**
 * Thrown when an account cannot be removed because something still depends on
 * it (FR-009, FR-010).
 *
 * Marking an account inactive is always allowed and is the intended way to
 * retire one that carries history (FR-011) — inactive blocks future postings
 * without rewriting the past.
 */
final class AccountNotDeletable extends DomainException
{
    public static function hasChildren(string $accountCode, int $childCount): self
    {
        return new self(__('admin.accounting.errors.account_has_children', [
            'code' => $accountCode,
            'count' => $childCount,
        ]));
    }

    public static function hasJournalLines(string $accountCode, int $lineCount): self
    {
        return new self(__('admin.accounting.errors.account_has_lines', [
            'code' => $accountCode,
            'count' => $lineCount,
        ]));
    }
}
