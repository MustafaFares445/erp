<?php

declare(strict_types=1);

namespace App\Enums;

enum WriteOffStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Approved, self::Cancelled], true),
            self::Approved, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Cancelled], true);
    }

    public function label(): string
    {
        return __('admin.accounting.write_off_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Approved => 'danger',
            self::Cancelled => 'warning',
        };
    }
}
