<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use DomainException;

/**
 * Thrown when a journal entry cannot be posted because its debits and credits
 * do not agree, or because it has too few lines to be a double-entry posting at
 * all (FR-020, FR-024).
 *
 * Canonical Accounting rules require balanced double-entry posting. The exception
 * carries both totals rather than a generic failure so the accountant can see the
 * size of the gap.
 */
final class UnbalancedJournalEntry extends DomainException
{
    public static function totals(string $debitTotal, string $creditTotal): self
    {
        return new self(__('admin.accounting.errors.unbalanced_entry', [
            'debit' => $debitTotal,
            'credit' => $creditTotal,
        ]));
    }

    public static function tooFewLines(int $lineCount): self
    {
        return new self(__('admin.accounting.errors.too_few_lines', ['count' => $lineCount]));
    }
}
