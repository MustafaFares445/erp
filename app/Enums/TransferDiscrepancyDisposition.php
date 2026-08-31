<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferDiscrepancyDisposition: string
{
    case Shortage = 'shortage';
    case Damaged = 'damaged';
    case Cancelled = 'cancelled';
}
