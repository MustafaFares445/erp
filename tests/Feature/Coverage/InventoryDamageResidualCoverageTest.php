<?php

declare(strict_types=1);

use App\Data\Inventory\StockDamageData;
use App\Enums\MovementType;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryDamageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects a lot that has no source-condition balance in the stock warehouse', function (): void {
    $stockWarehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $stock = InventoryStock::factory()->for($variant)->for($stockWarehouse)->create();
    $lot = InventoryLot::factory()
        ->for($variant, 'productVariant')
        ->for($otherWarehouse)
        ->create();

    $method = new ReflectionMethod(InventoryDamageService::class, 'validatedLot');

    expect(fn () => $method->invoke(
        app(InventoryDamageService::class),
        $stock,
        new StockDamageData(1, 'Coverage damage', inventoryLotId: $lot->getKey()),
        MovementType::Damage,
    ))->toThrow(DomainException::class, __('admin.inventory.lot.errors.required'));
});

it('rejects a serialized damage reference that no longer resolves', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create();

    $method = new ReflectionMethod(InventoryDamageService::class, 'validateSerializedUnit');

    expect(fn () => $method->invoke(
        app(InventoryDamageService::class),
        $stock,
        new StockDamageData(1, 'Coverage damage', serializedInventoryUnitId: PHP_INT_MAX),
        MovementType::Damage,
    ))->toThrow(DomainException::class, __('admin.inventory.damage.errors.invalid_serial'));
});

it('normalizes numeric-string inventory stock foreign identifiers', function (): void {
    $stock = new InventoryStock;
    $stock->setRawAttributes(['warehouse_id' => '123'], true);

    $method = new ReflectionMethod(InventoryDamageService::class, 'stockForeignId');

    expect($method->invoke(app(InventoryDamageService::class), $stock, 'warehouse_id'))->toBe(123);
});
