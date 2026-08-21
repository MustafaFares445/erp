<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use App\Models\SupplierConfirmation;
use DomainException;

/**
 * Thrown when an answered confirmation is asked to change (V-11, FR-031).
 *
 * The supplier's original answer is evidence, and the receiving-performance
 * report is built from it. Overwriting it would destroy the only record of what
 * was actually promised, so a correction appends a new row instead.
 */
final class ConfirmationNotAmendable extends DomainException
{
    public static function alreadyAnswered(SupplierConfirmation $confirmation): self
    {
        return new self(__('admin.purchasing.errors.confirmation_not_amendable', [
            'status' => $confirmation->confirmation_status->label(),
        ]));
    }
}
