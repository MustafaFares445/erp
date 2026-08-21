<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use DomainException;

/**
 * Thrown when one line of an otherwise postable entry is not a valid
 * double-entry line (FR-021, FR-022).
 *
 * Every constructor carries the line's 1-based position, because "a line is
 * invalid" is not actionable on an entry with a dozen lines.
 */
final class InvalidJournalEntryLine extends DomainException
{
    public static function bothSidesSet(int $position): self
    {
        return new self(__('admin.accounting.errors.line_both_sides', ['position' => $position]));
    }

    public static function neitherSideSet(int $position): self
    {
        return new self(__('admin.accounting.errors.line_neither_side', ['position' => $position]));
    }

    public static function negativeAmount(int $position): self
    {
        return new self(__('admin.accounting.errors.line_negative', ['position' => $position]));
    }

    public static function accountNotPostable(int $position, string $accountCode): self
    {
        return new self(__('admin.accounting.errors.line_account_not_postable', [
            'position' => $position,
            'code' => $accountCode,
        ]));
    }

    public static function accountInactive(int $position, string $accountCode): self
    {
        return new self(__('admin.accounting.errors.line_account_inactive', [
            'position' => $position,
            'code' => $accountCode,
        ]));
    }
}
