<?php

declare(strict_types=1);

namespace App\Services\Crm\Exceptions;

use App\Enums\CustomerQuotationRequestStatus;
use App\Services\Crm\CustomerQuotationRequestService;
use DomainException;

/**
 * Thrown when {@see CustomerQuotationRequestService} is
 * asked for a transition the request's current
 * {@see CustomerQuotationRequestStatus} does not allow, or the
 * customer/lines fail a precondition (approved customer, valid variant, a
 * delivery address that belongs to the customer, etc.).
 */
final class InvalidCustomerQuotationRequestTransition extends DomainException {}
