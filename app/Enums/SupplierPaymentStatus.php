<?php

declare(strict_types=1);

namespace App\Enums;

enum SupplierPaymentStatus: string
{
    case Draft = 'draft';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Paid, self::Cancelled], true),
            self::Paid, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled], true);
    }

    public function label(): string
    {
        return __('admin.accounting.supplier_payment_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Paid => 'success',
            self::Cancelled => 'danger',
        };
    }
}
