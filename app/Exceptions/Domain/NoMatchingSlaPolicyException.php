<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** No active SLA policy applies to a ticket, so the caller may fall back to the built-in priority defaults. */
final class NoMatchingSlaPolicyException extends DomainException
{
    public static function forTicket(): self
    {
        return new self('No active SLA policy matches this support ticket.');
    }
}
