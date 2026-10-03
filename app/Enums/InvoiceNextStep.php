<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Sales\InvoiceNextActionResolver;

/**
 * The single operational step the system knows for an invoice, as decided by
 * {@see InvoiceNextActionResolver::step()}.
 */
enum InvoiceNextStep: string
{
    case Issue = 'issue';
    case RetryDepositApplication = 'retry_deposit_application';
    case RecordPayment = 'record_payment';
}
