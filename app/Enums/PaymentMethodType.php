<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The `payment_methods.type` column stays a plain string for backward
 * compatibility with any value already stored; this enum is the closed set
 * the Filament form offers going forward, `Stripe` being the new online
 * provider option (Customer App V1 §9/§13).
 */
enum PaymentMethodType: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Cheque = 'cheque';
    case Other = 'other';
    case Stripe = 'stripe';

    public function label(): string
    {
        return __('admin.payments.method_type.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
