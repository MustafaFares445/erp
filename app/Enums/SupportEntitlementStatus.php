<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportEntitlementStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
            self::Expired => __('Expired'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
