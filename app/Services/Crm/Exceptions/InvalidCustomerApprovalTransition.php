<?php

declare(strict_types=1);

namespace App\Services\Crm\Exceptions;

use App\Enums\CustomerApprovalStatus;
use App\Models\CustomerProfile;
use App\Services\Crm\CustomerApprovalService;
use DomainException;

/**
 * Thrown when {@see CustomerApprovalService} is asked to
 * move a {@see CustomerProfile} through a transition its current
 * {@see CustomerApprovalStatus} does not allow.
 */
final class InvalidCustomerApprovalTransition extends DomainException
{
    public static function fromTo(string $from, string $to): self
    {
        return new self(__('admin.crm.errors.invalid_approval_transition', [
            'from' => $from,
            'to' => $to,
        ]));
    }
}
