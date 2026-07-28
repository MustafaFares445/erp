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
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a package is scoped directly to its warehouse', function (): void {
    $warehouse = Warehouse::factory()->create();
    $package = Package::factory()->for($warehouse)->create();

    expect($package->warehouse_id)->toBe($warehouse->getKey())
        ->and($package->getAttributes())->not->toHaveKey('warehouse_location_id');
});

test('a transfer moves a package with its recorded goods and copies it to both ledger lines', function (): void {
    $sourceWarehouse = Warehouse::factory()->create();
    $destinationWarehouse = Warehouse::factory()->create();
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
        'package_id' => $package->getKey(),
    ]);

    $service = app(InventoryOperationService::class);
    $actor = User::factory()->create();
    $service->markReady($operation);
    $service->dispatch($operation->refresh(), $actor);
    $service->complete($operation->refresh(), $actor);

    expect($package->refresh()->warehouse_id)->toBe($destinationWarehouse->getKey())
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
        ->toThrow(DomainException::class, __('admin.package.errors.warehouse_mismatch'));
});

test('a stock level drills into its line-grained movements for package visibility', function (): void {
    $stock = InventoryStock::factory()->for(ProductVariant::factory())->for(Warehouse::factory())->create();
    $url = StockLevelsTable::packageMovementsUrl($stock);

    expect($url)->toContain('stock-movements')
        ->and($url)->toContain('warehouse_id')
        ->and($url)->toContain('product_variant_id');
});
