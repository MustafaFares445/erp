<?php

declare(strict_types=1);

namespace App\Enums;

enum TicketAssignmentSource: string
{
    case Manual = 'manual';
    case Routing = 'routing';
    case Automation = 'automation';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Manual'),
            self::Routing => __('Routing'),
            self::Automation => __('Automation'),
        };
    }
}
