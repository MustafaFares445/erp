<?php

declare(strict_types=1);

namespace App\Services\Sales\Exceptions;

use App\Models\JournalEntry;
use DomainException;

/**
 * Thrown when a quotation's customer, lines, quantities, prices, or totals
 * are changed after it has been sent (FR-023).
 *
 * Enforced at both the service and model layer, following the
 * {@see JournalEntry} / `PostedEntryIsImmutable` precedent:
 * immutability is an invariant, not a privilege, so it must hold even
 * against a direct model write that bypasses the service.
 */
final class QuotationImmutable extends DomainException
{
    public static function forQuotation(string $quotationNumber): self
    {
        return new self(__('admin.sales.errors.quotation_immutable', ['number' => $quotationNumber]));
    }
}
