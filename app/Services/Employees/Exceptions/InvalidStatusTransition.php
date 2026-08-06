<?php

declare(strict_types=1);

namespace App\Services\Employees\Exceptions;

use DomainException;

/**
 * Thrown when a domain service is asked to move a stateful entity through a
 * transition its status enum's `canTransitionTo()` rejects
 * (contracts/plan-lifecycle.md, FR-008).
 */
final class InvalidStatusTransition extends DomainException
{
    public static function fromTo(string $from, string $to): self
    {
        return new self(__('admin.employees.errors.invalid_status_transition', [
            'from' => $from,
            'to' => $to,
        ]));
    }
}
