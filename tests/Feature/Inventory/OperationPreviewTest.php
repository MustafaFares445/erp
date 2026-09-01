<?php

declare(strict_types=1);

use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// FR-010, SRS §5.1: previewEffect() shows the resulting balance change per line before the
// administrator commits, and mutates nothing.

function previewService(): InventoryOperationService
{
    return app(InventoryOperationService::class);
}

it('previews the destination balance increase for a receipt without mutating anything', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($destination)->create(['on_hand_quantity' => '3.000', 'available_quantity' => '3.000']);
    $operation = InventoryOperation::factory()->receipt()->ready()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '4.000', 'unit_id' => $variant->unit_id]);

    $preview = previewService()->previewEffect($operation);

    expect($preview)->toHaveCount(1);

    $line = $preview[0];

    expect($line['product_variant_id'])->toBe($variant->getKey())
        ->and($line['warehouse_id'])->toBe($destination->getKey())
        ->and((float) $line['before'])->toBe(3.0)
        ->and((float) $line['after'])->toBe(7.0);

    expect((float) InventoryStock::query()->where('product_variant_id', $variant->getKey())->where('warehouse_id', $destination->getKey())->value('on_hand_quantity'))->toBe(3.0)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and($operation->refresh()->isReady())->toBeTrue();
});

it('previews the source balance decrease for a delivery without mutating anything', function (): void {
    $source = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($source)->create(['on_hand_quantity' => '10.000', 'available_quantity' => '10.000']);
    $operation = InventoryOperation::factory()->delivery()->ready()->create(['source_warehouse_id' => $source->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '6.000', 'unit_id' => $variant->unit_id]);

    $line = previewService()->previewEffect($operation)[0];

    expect((float) $line['before'])->toBe(10.0)
        ->and((float) $line['after'])->toBe(4.0)
        ->and((float) InventoryStock::query()->where('product_variant_id', $variant->getKey())->where('warehouse_id', $source->getKey())->value('on_hand_quantity'))->toBe(10.0);
});

it('previews the destination gain for an internal transfer that is already InTransit', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $operation = InventoryOperation::factory()->internalTransfer()->inTransit()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $transferLine = $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '2.000',
        'unit_id' => $variant->unit_id,
    ]);
    $transferLine->forceFill(['dispatched_base_quantity' => '2.000000'])->save();

    $line = previewService()->previewEffect($operation)[0];

    expect($line['warehouse_id'])->toBe($destination->getKey())
        ->and((float) $line['before'])->toBe(0.0)
        ->and((float) $line['after'])->toBe(2.0);
});

it('previews multiple lines independently', function (): void {
    $destination = Warehouse::factory()->create();
    $variantA = ProductVariant::factory()->create();
    $variantB = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variantA)->for($destination)->create(['on_hand_quantity' => '1.000', 'available_quantity' => '1.000']);
    $operation = InventoryOperation::factory()->receipt()->ready()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variantA->getKey(), 'quantity' => '2.000', 'unit_id' => $variantA->unit_id]);
    $operation->lines()->create(['product_variant_id' => $variantB->getKey(), 'quantity' => '5.000', 'unit_id' => $variantB->unit_id]);

    $preview = previewService()->previewEffect($operation);

    expect($preview)->toHaveCount(2);

    $lineA = collect($preview)->firstWhere('product_variant_id', $variantA->getKey());
    $lineB = collect($preview)->firstWhere('product_variant_id', $variantB->getKey());

    expect((float) $lineA['before'])->toBe(1.0)
        ->and((float) $lineA['after'])->toBe(3.0)
        ->and((float) $lineB['before'])->toBe(0.0)
        ->and((float) $lineB['after'])->toBe(5.0);
});

// availableQuantity()/availableQuantitiesFor() are the read counterpart of the same P-2
// boundary: a live balance check for callers with no InventoryOperation to preview against
// yet, such as the create-operation wizard's stock warnings and quantity placeholder.

it('reports the available quantity for a single variant and warehouse pair', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '7.500']);

    expect(previewService()->availableQuantity($variant->getKey(), $warehouse->getKey()))->toBe(7.5);
});

it('returns a null available quantity when no stock row exists yet', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    expect(previewService()->availableQuantity($variant->getKey(), $warehouse->getKey()))->toBeNull();
});

it('batches available quantities and variant names for several variants in one warehouse', function (): void {
    $warehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $variantA = ProductVariant::factory()->create(['name' => 'Widget A']);
    $variantB = ProductVariant::factory()->create(['name' => 'Widget B']);
    InventoryStock::factory()->for($variantA)->for($warehouse)->create(['available_quantity' => '3.000']);
    InventoryStock::factory()->for($variantB)->for($warehouse)->create(['available_quantity' => '9.250']);
    InventoryStock::factory()->for($variantA)->for($otherWarehouse)->create(['available_quantity' => '99.000']);

    $quantities = previewService()->availableQuantitiesFor([$variantA->getKey(), $variantB->getKey()], $warehouse->getKey());

    expect($quantities)->toHaveCount(2)
        ->and($quantities[$variantA->getKey()])->toBe(['available_quantity' => 3.0, 'variant_name' => 'Widget A'])
        ->and($quantities[$variantB->getKey()])->toBe(['available_quantity' => 9.25, 'variant_name' => 'Widget B']);
});
