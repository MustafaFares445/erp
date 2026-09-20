<?php

declare(strict_types=1);

use App\Support\MoneyFormatter;

it('formats minor units as a currency string for the given code', function (): void {
    expect(MoneyFormatter::format(21420, 'USD'))->toBe('$214.20')
        ->and(MoneyFormatter::format(0, 'AED'))->toBe("AED\u{00A0}0.00")
        ->and(MoneyFormatter::format(-500, 'USD'))->toBe('-$5.00');
});
