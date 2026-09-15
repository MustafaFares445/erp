<?php

declare(strict_types=1);

use App\Support\QuantityFormatter;

it('removes display-only trailing zeroes without changing quantity precision', function (): void {
    expect(QuantityFormatter::display('0.000000'))->toBe('0')
        ->and(QuantityFormatter::display('10.000000'))->toBe('10')
        ->and(QuantityFormatter::display('1234.250000'))->toBe('1,234.25')
        ->and(QuantityFormatter::display('0.125000'))->toBe('0.125');
});
