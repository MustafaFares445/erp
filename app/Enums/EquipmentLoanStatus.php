<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EquipmentLoanStatus: string implements HasColor, HasLabel
{
    use HasTranslatedLabel;

    case Reserved = 'reserved';
    case Issued = 'issued';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /** Reserved and issued loans hold the loaner unit; nothing else may use it. */
    public function isActive(): bool
    {
        return in_array($this, [self::Reserved, self::Issued], true);
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::Reserved->value, self::Issued->value];
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Reserved => 'info',
            self::Issued => 'warning',
            self::Returned => 'success',
            self::Cancelled => 'gray',
        };
    }
}
