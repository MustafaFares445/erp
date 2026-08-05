<?php

declare(strict_types=1);

namespace App\Enums;

enum ShipmentStatus: string
{
    case InTransit = 'in_transit';
    case Arrived = 'arrived';

    public function label(): string
    {
        return __('admin.shipment.statuses.'.$this->value);
    }
}
