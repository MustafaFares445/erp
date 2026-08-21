<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use Carbon\CarbonInterface;
use DomainException;

/**
 * Rejects a confirmation whose target or promised date makes no sense (V-09,
 * V-10, FR-028, FR-030).
 *
 * The morph column is a `varchar` and will accept any class name the database
 * is given, so the two-type restriction has to be enforced here. A confirmation
 * attached to a stock transfer would be meaningless and would break every
 * report that assumes the target is a document a supplier can answer for.
 */
final class InvalidConfirmationTarget extends DomainException
{
    public static function unsupportedType(): self
    {
        return new self(__('admin.purchasing.errors.invalid_confirmation_target'));
    }

    public static function promisedBeforeOrdered(CarbonInterface $promised, CarbonInterface $ordered): self
    {
        return new self(__('admin.purchasing.errors.promised_before_ordered', [
            'promised' => $promised->toDateString(),
            'ordered' => $ordered->toDateString(),
        ]));
    }
}
