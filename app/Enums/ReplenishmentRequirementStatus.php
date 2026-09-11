<?php

declare(strict_types=1);

namespace App\Enums;

enum ReplenishmentRequirementStatus: string
{
    case Open = 'open';
    case PartiallyCovered = 'partially_covered';
    case Covered = 'covered';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}
