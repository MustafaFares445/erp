<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\CreditNote;
use App\Services\Sales\CreditNoteService;

/**
 * The lifecycle of a {@see CreditNote} (spec 019 data-model.md §9).
 *
 * Confirming freezes the note and posts its accounting correction
 * ({@see CreditNoteService::confirm()}); only a confirmed,
 * unreversed note may be reversed. A draft may instead be cancelled without
 * ever posting, mirroring the other sales documents' draft exit.
 */
enum CreditNoteStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Reversed, self::Cancelled => true,
            default => false,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Confirmed, self::Cancelled], true),
            self::Confirmed => $target === self::Reversed,
            self::Reversed, self::Cancelled => false,
        };
    }

    public function label(): string
    {
        return __('admin.sales.credit_note_status.'.$this->value);
    }
}
