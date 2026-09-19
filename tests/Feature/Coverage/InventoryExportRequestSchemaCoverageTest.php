<?php

declare(strict_types=1);

use App\Enums\InventoryExportType;
use App\Filament\Resources\InventoryReports\Schemas\InventoryExportRequestSchema;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds filter components for every inventory export type', function (): void {
    User::factory()->create(['name' => 'Coverage User']);
    Brand::factory()->create(['name' => 'Coverage Brand']);
    ProductCategory::factory()->create(['name' => 'Coverage Category']);
    Product::factory()->create(['name' => 'Coverage Product']);
    ProductVariant::factory()->create(['name' => 'Coverage Variant']);
    Warehouse::factory()->create(['name' => 'Coverage Warehouse']);
    $supplier = Supplier::factory()->create(['name' => 'Coverage Supplier']);
    foreach (InventoryExportType::cases() as $type) {
        $components = InventoryExportRequestSchema::make($type);
        expect($components)->not->toBeEmpty();
        foreach ($components as $component) {
            expect($component)->toBeObject();
        }
    }
});

it('normalizes only scalar inventory export option values', function (): void {
    $method = new ReflectionMethod(InventoryExportRequestSchema::class, 'stringOptions');

    $result = $method->invoke(null, [
        'string' => 'value',
        'int' => 12,
        'float' => 2.5,
        'null' => null,
        'array' => [],
        'object' => new stdClass,
    ]);

    expect($result)->toBe([
        'string' => 'value',
        'int' => '12',
        'float' => '2.5',
    ]);
});
