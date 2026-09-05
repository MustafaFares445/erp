<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => $target === self::Posted,
            self::Posted => $target === self::Reversed,
            self::Reversed => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Reversed;
    }

    public function label(): string
    {
        return __('admin.sales.payment_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Posted => 'success',
            self::Reversed => 'danger',
        };
    }
}
