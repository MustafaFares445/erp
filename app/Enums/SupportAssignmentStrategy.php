<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportAssignmentStrategy: string
{
    case Manual = 'manual';
    case LeastLoaded = 'least_loaded';
    case RoundRobin = 'round_robin';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Manual'),
            self::LeastLoaded => __('Least loaded'),
            self::RoundRobin => __('Round robin'),
        };
    }
}
