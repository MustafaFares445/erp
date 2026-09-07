<?php

declare(strict_types=1);

namespace App\Services\Support\Exceptions;

use App\Models\MaintenanceRecord;
use App\Services\Support\MaintenanceBillingService;
use DomainException;

/**
 * Thrown when {@see MaintenanceBillingService} is asked
 * to bill (or reclassify) a {@see MaintenanceRecord} job outside
 * the states GAP-MW-10's billing rules allow (WP-2.9).
 */
final class InvalidBillingTransition extends DomainException
{
    public static function notClosed(): self
    {
        return new self('Only a closed maintenance request may be billed.');
    }

    public static function alreadyBilled(string $billingType): self
    {
        return new self(sprintf('This maintenance request has already been billed (%s).', $billingType));
    }

    public static function warrantyMustBeReclassified(): self
    {
        return new self('A warranty-covered maintenance request cannot be invoiced until it is reclassified.');
    }

    public static function reasonRequired(): self
    {
        return new self('A reason is required to mark this maintenance request as warranty-covered.');
    }
}
