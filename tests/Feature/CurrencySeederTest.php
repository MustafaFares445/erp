<?php

declare(strict_types=1);

use App\Models\Currency;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the currencies required by the demo workflows', function (): void {
    $this->seed(CurrencySeeder::class);

    expect(Currency::query()->where('code', 'USD')->where('is_active', true)->exists())->toBeTrue()
        ->and(Currency::query()->where('code', 'AED')->where('is_default', true)->exists())->toBeTrue();
});

it('can be run repeatedly without duplicating currencies', function (): void {
    $this->seed(CurrencySeeder::class);
    $this->seed(CurrencySeeder::class);

    expect(Currency::query()->where('code', 'USD')->count())->toBe(1)
        ->and(Currency::query()->where('is_default', true)->count())->toBe(1);
});
