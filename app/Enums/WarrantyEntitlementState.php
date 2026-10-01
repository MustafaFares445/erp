<?php

declare(strict_types=1);

namespace App\Enums;

enum WarrantyEntitlementState: string
{
    case PendingActivation = 'pending_activation';
    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingActivation => 'warning',
            self::Active => 'success',
            self::Ended => 'gray',
            self::Cancelled => 'danger',
        };
    }
}
