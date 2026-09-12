<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationDigestCadence: string
{
    case Immediate = 'immediate';
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'Immediate',
            self::Daily => 'Daily digest',
            self::Weekly => 'Weekly digest',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
