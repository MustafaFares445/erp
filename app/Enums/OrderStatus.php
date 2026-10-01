<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Released = 'released';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => __(__('Draft')),
            self::Confirmed => __(__('Confirmed')),
            self::Released => __(__('Released')),
            self::Closed => __(__('Closed')),
            self::Cancelled => __(__('Cancelled')),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Confirmed => 'info',
            self::Released => 'primary',
            self::Closed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
