<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryType: string
{
    case Inner = 'inner';
    case Outer = 'outer';

    public function label(): string
    {
        return __('admin.inventory.operation.delivery_types.'.$this->value);
    }
}
