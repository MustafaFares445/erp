<?php

declare(strict_types=1);

use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Warehouse;
use Database\Seeders\InventoryDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds idempotent inventory data for manual smoke testing', function (): void {
    $seeder = new InventoryDemoSeeder;

    $seeder->run();
    $seeder->run();

    expect(Warehouse::query()->whereIn('code', ['DEMO-CENTRAL', 'DEMO-WEST'])->count())->toBe(2)
        ->and(InventoryStock::query()->count())->toBe(3)
        ->and(InventoryMovement::query()->count())->toBe(3)
        ->and(InventoryStock::query()->whereNull('reorder_level')->count())->toBe(1)
        ->and(InventoryStock::query()->whereColumn('available_quantity', '<=', 'reorder_level')->count())->toBe(1)
        ->and(InventoryMovement::query()->where('source_type', 'delivery_note')->exists())->toBeTrue();
});
