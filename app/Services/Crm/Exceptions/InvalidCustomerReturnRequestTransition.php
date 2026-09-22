<?php

declare(strict_types=1);

namespace App\Services\Crm\Exceptions;

use App\Enums\CustomerReturnRequestStatus;
use App\Services\Crm\CustomerReturnRequestService;
use DomainException;

/**
 * Thrown when {@see CustomerReturnRequestService} is asked for a transition
 * the request's current {@see CustomerReturnRequestStatus} does not allow,
 * or the customer/delivery/lines fail a precondition (approved customer, a
 * completed delivery that belongs to the customer, a line that belongs to
 * that same delivery, etc.).
 */
final class InvalidCustomerReturnRequestTransition extends DomainException {}
