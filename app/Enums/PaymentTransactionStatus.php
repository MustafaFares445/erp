<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Services\Payments\ProviderPaymentSettlementService;

/**
 * The provider-side lifecycle of a {@see PaymentTransaction}.
 *
 * Deliberately independent of {@see PaymentStatus} — a provider transaction
 * answers "did the collection succeed with the provider", an ERP
 * {@see Payment} answers "what did the ERP recognise". A
 * {@see ProviderPaymentSettlementService} bridges the
 * two exactly once per successful transaction.
 */
enum PaymentTransactionStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function label(): string
    {
        return __('admin.payments.transaction_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::RequiresAction => 'warning',
            self::Succeeded => 'success',
            self::Failed, self::Cancelled => 'danger',
            self::PartiallyRefunded, self::Refunded => 'gray',
        };
    }

    public function isSettleable(): bool
    {
        return $this === self::Succeeded;
    }
}
