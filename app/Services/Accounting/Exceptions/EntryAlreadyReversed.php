<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use DomainException;

/**
 * Thrown when an entry that already has a posted reversal is reversed again
 * (FR-028).
 *
 * The message names the existing reversal so the accountant can go read it
 * rather than wondering what blocked them. This check is also why "a reversal of
 * a reversal" needs no separate rule: the second attempt finds the first.
 */
final class EntryAlreadyReversed extends DomainException
{
    public static function by(string $reversalEntryNumber): self
    {
        return new self(__('admin.accounting.errors.already_reversed', [
            'entry' => $reversalEntryNumber,
        ]));
    }
}
