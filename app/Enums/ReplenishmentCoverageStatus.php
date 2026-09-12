<?php

declare(strict_types=1);

namespace App\Enums;

enum ReplenishmentCoverageStatus: string
{
    case Active = 'active';
    case Fulfilled = 'fulfilled';
    case Released = 'released';
    case Cancelled = 'cancelled';
}
