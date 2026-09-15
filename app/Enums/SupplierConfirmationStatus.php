<?php

declare(strict_types=1);

namespace App\Enums;

enum SupplierConfirmationStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    public function isAnswered(): bool
    {
        return $this !== self::Pending;
    }

    public function canTransitionTo(self $target): bool
    {
        return $this === self::Pending && $target->isAnswered();
    }

    public function label(): string
    {
        return __('admin.purchasing.confirmation_status.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
