<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Services\Settings\CurrencyCatalogService;
use Filament\Forms\Components\Select;

final class CurrencySelect
{
    public static function make(string $name): Select
    {
        return Select::make($name)
            ->options(fn (): array => app(CurrencyCatalogService::class)->activeOptions())
            ->default(fn (): string => app(CurrencyCatalogService::class)->defaultCode())
            ->searchable()
            ->preload()
            ->native(false);
    }
}
