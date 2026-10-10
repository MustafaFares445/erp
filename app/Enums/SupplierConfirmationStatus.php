<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SupplierConfirmationStatus: string implements HasColor, HasLabel
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

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Partial => 'warning',
            self::Confirmed => 'success',
            self::Rejected => 'danger',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
