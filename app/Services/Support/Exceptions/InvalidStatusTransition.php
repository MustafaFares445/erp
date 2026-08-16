<?php

declare(strict_types=1);

namespace App\Services\Support\Exceptions;

use DomainException;

/**
 * Thrown when a domain service is asked to move a stateful Support entity
 * through a transition its status enum's `canTransitionTo()` rejects
 * (contracts/ticket-lifecycle.md §1, contracts/maintenance-lifecycle.md §1,
 * FR-008).
 */
final class InvalidStatusTransition extends DomainException
{
    public static function fromTo(string $from, string $to): self
    {
        return new self(__('admin.support.errors.invalid_status_transition', [
            'from' => $from,
            'to' => $to,
        ]));
    }
}
