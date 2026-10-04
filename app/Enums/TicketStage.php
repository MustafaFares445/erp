<?php

declare(strict_types=1);

namespace App\Enums;

enum TicketStage: string
{
    case Intake = 'intake';
    case Triage = 'triage';
    case ActiveSupport = 'active_support';
    case Resolution = 'resolution';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Intake => __('Intake'),
            self::Triage => __('Triage'),
            self::ActiveSupport => __('Active support'),
            self::Resolution => __('Resolution'),
            self::Closed => __('Closed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Intake => 'gray',
            self::Triage => 'warning',
            self::ActiveSupport => 'primary',
            self::Resolution => 'success',
            self::Closed => 'gray',
        };
    }
}
