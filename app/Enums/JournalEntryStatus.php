<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\JournalEntry;
use App\Services\Accounting\JournalPostingService;

/**
 * A journal entry's lifecycle, which is one-way: `draft` -> `posted`.
 *
 * There is deliberately no `void`, `cancelled`, or `reversed` case. A posted
 * entry is immutable (FR-025), so the only correction is a separate reversing
 * entry — which is itself an ordinary posted entry, not a state of the original.
 * Whether an entry has been reversed is answered by
 * {@see JournalEntry::reversal()}, never by a status column.
 *
 * @see JournalPostingService
 * @see /specs/018-chart-of-accounts-journals/contracts/journal-posting.md
 */
enum JournalEntryStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function isPosted(): bool
    {
        return $this === self::Posted;
    }

    public function label(): string
    {
        return __('admin.accounting.entry_status.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
