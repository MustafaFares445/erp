<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Sent = 'sent';
    case WrittenOff = 'written_off';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Issued, self::Cancelled], true),
            self::Issued => in_array($target, [self::Sent, self::Cancelled], true),
            self::Sent => in_array($target, [self::WrittenOff, self::Cancelled], true),
            self::WrittenOff, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::WrittenOff, self::Cancelled], true);
    }

    public function label(): string
    {
        return __('admin.sales.invoice_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued => 'warning',
            self::Sent => 'info',
            self::WrittenOff, self::Cancelled => 'danger',
        };
    }
}
