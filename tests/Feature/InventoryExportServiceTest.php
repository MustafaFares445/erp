<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryExportService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('creates a private asynchronous stock export with the requested filters and audit trail', function (): void {
    Storage::fake('local');
    (new InventoryPermissionSeeder)->run();
    $actor = User::factory()->create();
    $actor->givePermissionTo(InventoryPermission::Export->value);

    $warehouse = Warehouse::factory()->create();
    InventoryStock::factory()->for(ProductVariant::factory())->for($warehouse)->create();

    $export = app(InventoryExportService::class)->request('stock_levels', ['warehouse_id' => $warehouse->getKey()], $actor);

    expect($export->fresh()->status)->toBe('completed')
        ->and($export->fresh()->file_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $export->fresh()->file_path))->toBeTrue();
});
