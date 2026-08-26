<?php

declare(strict_types=1);

namespace App\Services\Sales\Exceptions;

use DomainException;

/**
 * Thrown when a quotation is attempted from a sales opportunity that is not
 * approved, has no resolvable customer, or already has a quotation (FR-025).
 */
final class OpportunityNotQuotable extends DomainException
{
    public static function notApproved(): self
    {
        return new self(__('admin.sales.errors.opportunity_not_approved'));
    }

    public static function noCustomer(): self
    {
        return new self(__('admin.sales.errors.opportunity_no_customer'));
    }

    public static function alreadyQuoted(string $quotationNumber): self
    {
        return new self(__('admin.sales.errors.opportunity_already_quoted', ['number' => $quotationNumber]));
    }
}
