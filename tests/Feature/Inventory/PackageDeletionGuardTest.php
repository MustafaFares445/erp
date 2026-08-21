<?php

declare(strict_types=1);

use App\Models\InventoryOperation;
use App\Models\Package;
use App\Models\PackageType;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('a package referenced by an operation line cannot be deleted or moved to another warehouse', function (): void {
    $warehouse = Warehouse::factory()->create();
    $package = Package::factory()->for($warehouse)->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $warehouse->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'package_id' => $package->getKey(),
    ]);

    expect(fn () => $package->delete())
        ->toThrow(ValidationException::class, __('admin.package.errors.referenced'));

    $otherWarehouse = Warehouse::factory()->create();

    expect(fn () => $package->update(['warehouse_id' => $otherWarehouse->getKey()]))
        ->toThrow(ValidationException::class, __('admin.package.errors.warehouse_move_with_goods'));
});

test('a package type with packages cannot be deleted', function (): void {
    $type = PackageType::factory()->create();
    Package::factory()->for($type, 'packageType')->create();

    expect(fn () => $type->delete())
        ->toThrow(ValidationException::class, __('admin.package.errors.type_referenced'));
});
