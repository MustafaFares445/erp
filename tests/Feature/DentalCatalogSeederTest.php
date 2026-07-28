<?php

declare(strict_types=1);

use App\Models\ProductVariant;
use Database\Seeders\DentalCatalogSeeder;
use Database\Seeders\InventoryDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('replaces legacy demo rows when the dental catalogue is seeded', function (): void {
    (new InventoryDemoSeeder)->run();

    expect(ProductVariant::query()->where('sku', 'like', 'DEMO-%')->exists())->toBeFalse();

    (new DentalCatalogSeeder)->run();

    expect(ProductVariant::query()->where('sku', 'like', 'DEMO-%')->exists())->toBeFalse()
        ->and(ProductVariant::query()->where('sku', 'FORMLABS-FORM-4B')->exists())->toBeTrue();
});
