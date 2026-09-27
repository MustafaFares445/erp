<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentProvider: string
{
    case Stripe = 'stripe';

    public function label(): string
    {
        return __('admin.payments.provider.'.$this->value);
    }
}
