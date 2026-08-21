<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use DomainException;

/**
 * Thrown when a journal entry cannot be posted because its debits and credits
 * do not agree, or because it has too few lines to be a double-entry posting at
 * all (FR-020, FR-024).
 *
 * FR-020 is the single acceptance criterion `Docs/IMPLEMENTATION_PLAN.md` §6
 * states outright, which is why the message carries both totals rather than a
 * generic failure: the accountant needs to know the size of the gap.
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
