<?php

declare(strict_types=1);

namespace App\Enums;

enum ShipmentStatus: string
{
    case Planned = 'planned';
    case InTransit = 'in_transit';
    case Arrived = 'arrived';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::InTransit => 'In Transit',
            self::Arrived => 'Arrived',
            self::Cancelled => 'Cancelled',
        };
    }
}
