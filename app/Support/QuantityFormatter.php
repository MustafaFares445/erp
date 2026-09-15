<?php

declare(strict_types=1);

namespace App\Support;

final class QuantityFormatter
{
    public static function display(mixed $quantity): string
    {
        if (! is_numeric($quantity)) {
            return '0';
        }

        $formatted = number_format((float) $quantity, 6, '.', ',');
        $formatted = mb_rtrim($formatted, '0');
        $formatted = mb_rtrim($formatted, '.');

        return $formatted === '-0' ? '0' : $formatted;
    }
}
