<?php

declare(strict_types=1);

namespace App\Services\Sales\Exceptions;

use DomainException;

/**
 * Thrown when a quotation decision or conversion is attempted from a status
 * that does not allow it (FR-021, FR-022, contracts/lifecycles.md §1).
 */
final class InvalidQuotationTransition extends DomainException
{
    public static function notSent(string $quotationNumber): self
    {
        return new self(__('admin.sales.errors.not_sent', ['number' => $quotationNumber]));
    }

    public static function expired(string $quotationNumber, string $date): self
    {
        return new self(__('admin.sales.errors.expired', ['number' => $quotationNumber, 'date' => $date]));
    }

    public static function alreadyConverted(string $quotationNumber, string $orderNumber): self
    {
        return new self(__('admin.sales.errors.already_converted', ['number' => $quotationNumber, 'order' => $orderNumber]));
    }

    public static function notAcceptedStatus(string $quotationNumber, string $status): self
    {
        return new self(__('admin.sales.errors.not_acceptable_status', ['number' => $quotationNumber, 'status' => $status]));
    }
}
