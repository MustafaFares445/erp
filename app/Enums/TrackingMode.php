<?php

declare(strict_types=1);

namespace App\Enums;

enum TrackingMode: string
{
    case None = 'none';
    case Lot = 'lot';
    case Serial = 'serial';

    public function label(): string
    {
        return match ($this) {
            self::None => __('No lot / serial tracking'),
            self::Lot => __('Lot / batch tracking'),
            self::Serial => __('Serial tracking'),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(static fn (self $mode): array => [$mode->value => $mode->label()])
            ->all();
    }
}
