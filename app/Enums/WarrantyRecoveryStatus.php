<?php

declare(strict_types=1);

namespace App\Enums;

enum WarrantyRecoveryStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'info',
            self::Approved, self::PartiallyReceived => 'warning',
            self::Received => 'success',
            self::Rejected, self::Cancelled => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Received, self::Rejected, self::Cancelled], true);
    }
}
