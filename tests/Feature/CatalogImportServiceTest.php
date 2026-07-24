<?php

declare(strict_types=1);

use App\Models\InventoryImportRun;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\CatalogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('parses a private XLSX template then confirms its valid rows idempotently', function (): void {
    Storage::fake('local');
    $actor = User::factory()->create();
    $path = 'catalog-imports/catalog-import.xlsx';
    $service = app(CatalogImportService::class);

    $service->writeTemplate(Storage::disk('local')->path($path));

    $run = InventoryImportRun::query()->create(['file_path' => $path, 'status' => 'queued', 'created_by' => $actor->getKey()]);

    $service->parse($run);

    expect($run->fresh()->status)->toBe('ready')
        ->and($run->fresh()->valid_rows)->toBe(1)
        ->and($run->items()->where('status', 'valid')->count())->toBe(1);

    $service->confirm($run, $actor);
    $secondRun = InventoryImportRun::query()->create(['file_path' => $path, 'status' => 'queued', 'created_by' => $actor->getKey()]);
    $service->parse($secondRun);
    $service->confirm($secondRun, $actor);

    expect(ProductVariant::query()->where('sku', 'SKU-001')->count())->toBe(1)
        ->and($run->fresh()->status)->toBe('confirmed')
        ->and($secondRun->fresh()->status)->toBe('confirmed');
});
