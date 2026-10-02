<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Settings\CurrencyCatalogService;
use Illuminate\Support\Number;

/**
 * Pairs a minor-unit integer with a currency code and formats it the same
 * way Filament's own `->money()` column/entry does (W4b), so Blade views
 * that can't reach a Filament component still render money consistently.
 */
final class MoneyFormatter
{
    public static function format(int $minorUnits, ?string $currency = null): string
    {
        $currency ??= app(CurrencyCatalogService::class)->defaultCode();

        return Number::currency($minorUnits / 100, $currency);
    }

    public static function formatAmount(string|int|float $amount, ?string $currency = null): string
    {
        $currency ??= app(CurrencyCatalogService::class)->defaultCode();

        return Number::currency((float) $amount, $currency);
    }
}
