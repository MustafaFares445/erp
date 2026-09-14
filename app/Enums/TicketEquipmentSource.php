<?php

declare(strict_types=1);

namespace App\Enums;

enum TicketEquipmentSource: string
{
    case SoldByUs = 'sold_by_us';
    case External = 'external';
}
