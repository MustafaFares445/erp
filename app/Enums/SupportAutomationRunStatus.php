<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportAutomationRunStatus: string
{
    case Running = 'running';
    case Skipped = 'skipped';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => __('Running'),
            self::Skipped => __('Skipped'),
            self::Succeeded => __('Succeeded'),
            self::Failed => __('Failed'),
        };
    }
}
