<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Accounting\JournalPostingService;
use DomainException;

/**
 * Thrown when anything attempts to change or remove a posted journal entry, or
 * to reverse an entry that was never posted (FR-025, FR-028).
 *
 * Raised from two layers by design (research.md R-002):
 * {@see JournalPostingService} refuses it on the authorized path, and the
 * {@see JournalEntry} / {@see JournalEntryLine} model guards refuse it on any
 * direct write. The duplication is the point — the model guard is what stops
 * code written later, by someone who does not know the rule, from rewriting
 * posted history.
 */
final class PostedEntryIsImmutable extends DomainException
{
    public static function forEntry(string $entryNumber): self
    {
        return new self(__('admin.accounting.errors.posted_immutable', ['entry' => $entryNumber]));
    }

    public static function forLineOf(string $entryNumber): self
    {
        return new self(__('admin.accounting.errors.posted_line_immutable', ['entry' => $entryNumber]));
    }

    public static function notPosted(string $entryNumber): self
    {
        return new self(__('admin.accounting.errors.not_posted', ['entry' => $entryNumber]));
    }
}
