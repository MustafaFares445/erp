<?php

declare(strict_types=1);

use App\Filament\Resources\StockLevels\Tables\StockLevelsTable;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('a package can use an active location from its own warehouse', function (): void {
    $warehouse = Warehouse::factory()->create();
    $location = WarehouseLocation::factory()->for($warehouse, 'warehouse')->create();

    $package = Package::factory()->for($warehouse)->create([
        'warehouse_location_id' => $location->getKey(),
    ]);

    expect($package->hasValidLocation())->toBeTrue()
        ->and($package->warehouse_location_id)->toBe($location->getKey());
});

test('a package rejects a location belonging to another warehouse', function (): void {
    $warehouse = Warehouse::factory()->create();
    $foreignLocation = WarehouseLocation::factory()->for(Warehouse::factory(), 'warehouse')->create();

    expect(fn () => Package::factory()->for($warehouse)->create([
        'warehouse_location_id' => $foreignLocation->getKey(),
    ]))->toThrow(ValidationException::class, __('admin.package.errors.location_mismatch'));
});

test('a package rejects an inactive warehouse location', function (): void {
    $warehouse = Warehouse::factory()->create();
    $inactiveLocation = WarehouseLocation::factory()->for($warehouse, 'warehouse')->create(['is_active' => false]);

    expect(fn () => Package::factory()->for($warehouse)->create([
        'warehouse_location_id' => $inactiveLocation->getKey(),
    ]))->toThrow(ValidationException::class, __('admin.package.errors.location_mismatch'));
});

test('a transfer moves a package with its recorded goods and copies it to both ledger lines', function (): void {
    $sourceWarehouse = Warehouse::factory()->create();
    $destinationWarehouse = Warehouse::factory()->create();
    $destinationLocation = WarehouseLocation::factory()->for($destinationWarehouse, 'warehouse')->create();
    $package = Package::factory()->for($sourceWarehouse)->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($sourceWarehouse)->for($variant)->create([
        'on_hand_quantity' => '2.000',
        'reserved_quantity' => '0.000',
        'available_quantity' => '2.000',
    ]);
    $operation = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $sourceWarehouse->getKey(),
        'destination_warehouse_id' => $destinationWarehouse->getKey(),
    ]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'warehouse_location_id' => $destinationLocation->getKey(),
        'package_id' => $package->getKey(),
    ]);

    $service = app(InventoryOperationService::class);
    $actor = User::factory()->create();
    $service->markReady($operation);
    $service->dispatch($operation->refresh(), $actor);
    $service->complete($operation->refresh(), $actor);

    expect($package->refresh()->warehouse_id)->toBe($destinationWarehouse->getKey())
        ->and($package->warehouse_location_id)->toBe($destinationLocation->getKey())
        ->and(InventoryMovement::query()->where('source_id', $operation->getKey())->pluck('package_id')->all())->toBe([
            $package->getKey(),
            $package->getKey(),
        ]);
});

test('an operation refuses a package from another warehouse before it reserves stock', function (): void {
    $warehouse = Warehouse::factory()->create();
    $foreignPackage = Package::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $warehouse->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '1.000',
        'unit_id' => $variant->unit_id,
        'package_id' => $foreignPackage->getKey(),
    ]);

    expect(fn () => app(InventoryOperationService::class)->markReady($operation))
        ->toThrow(DomainException::class, __('admin.package.errors.location_mismatch'));
});

test('a stock level drills into its line-grained movements for package visibility', function (): void {
    $stock = InventoryStock::factory()->for(ProductVariant::factory())->for(Warehouse::factory())->create();
    $url = StockLevelsTable::packageMovementsUrl($stock);

    expect($url)->toContain('stock-movements')
        ->and($url)->toContain('warehouse_id')
        ->and($url)->toContain('product_variant_id');
});
