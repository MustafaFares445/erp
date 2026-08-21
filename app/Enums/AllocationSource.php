<?php

declare(strict_types=1);

namespace App\Enums;

enum AllocationSource: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
}
