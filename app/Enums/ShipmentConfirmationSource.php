<?php

declare(strict_types=1);

namespace App\Enums;

enum ShipmentConfirmationSource: string
{
    case Customer = 'customer';
    case AdminUser = 'admin_user';
    case System = 'system';

    public function label(): string
    {
        return __('admin.shipment.confirmation_sources.'.$this->value);
    }
}
