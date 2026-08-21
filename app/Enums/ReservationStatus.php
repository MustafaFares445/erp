<?php

declare(strict_types=1);

namespace App\Enums;

enum ReservationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Expired = 'expired';
}
