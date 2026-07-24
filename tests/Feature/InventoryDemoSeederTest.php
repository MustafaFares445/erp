<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\DentalCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds an idempotent dental catalogue without demo inventory data', function (): void {
    $seeder = new DentalCatalogSeeder;

    $seeder->run();
    $seeder->run();

    expect(Brand::query()->whereIn('code', ['FORMLABS', 'DENTSPLY-SIRONA', 'IVOCLAR'])->count())->toBe(3)
        ->and(Product::query()->count())->toBe(7)
        ->and(ProductVariant::query()->count())->toBe(7)
        ->and(ProductVariant::query()->where('sku', 'like', 'DEMO-%')->exists())->toBeFalse();
});
