<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ShipmentConfirmationSource: string implements HasLabel
{
    case Customer = 'customer';
    case AdminUser = 'admin_user';
    case System = 'system';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return __('admin.shipment.confirmation_sources.'.$this->value);
    }
}
