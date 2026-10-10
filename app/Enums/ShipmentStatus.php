<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ShipmentStatus: string implements HasLabel
{
    case Planned = 'planned';
    case InTransit = 'in_transit';
    case Arrived = 'arrived';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return __('admin.shipment.statuses.'.$this->value);
    }
}
