<?php

declare(strict_types=1);

namespace App\Enums;

enum SlaMilestoneKey: string
{
    case FirstResponse = 'first_response';
    case Resolution = 'resolution';
    case Assignment = 'assignment';
    case OnsiteArrival = 'onsite_arrival';

    public function label(): string
    {
        return match ($this) {
            self::FirstResponse => __('First response'),
            self::Resolution => __('Resolution'),
            self::Assignment => __('Assignment'),
            self::OnsiteArrival => __('On-site arrival'),
        };
    }
}
