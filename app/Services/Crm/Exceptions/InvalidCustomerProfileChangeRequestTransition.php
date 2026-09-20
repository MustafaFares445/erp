<?php

declare(strict_types=1);

namespace App\Services\Crm\Exceptions;

use App\Models\CustomerProfileChangeRequest;
use App\Services\Crm\CustomerProfileChangeRequestService;
use DomainException;

/**
 * Thrown when {@see CustomerProfileChangeRequestService} is
 * asked to review a {@see CustomerProfileChangeRequest} that is
 * not Pending, or a customer proposes a field this workflow does not cover.
 */
final class InvalidCustomerProfileChangeRequestTransition extends DomainException
{
    public static function notPending(string $status): self
    {
        return new self(__('admin.crm.errors.change_request_not_pending', ['status' => $status]));
    }

    public static function unsupportedField(string $field): self
    {
        return new self(__('admin.crm.errors.change_request_unsupported_field', ['field' => $field]));
    }
}
