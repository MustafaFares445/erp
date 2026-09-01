<?php

declare(strict_types=1);

use App\Enums\InventoryReportType;
use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryReportFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function grainOperationService(): InventoryOperationService
{
    return app(InventoryOperationService::class);
}

it('carries a fractional grain quantity accurately through a receipt', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create(['net_weight' => 25]);
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '12.750',
        'unit_id' => $variant->unit_id,
    ]);
    $actor = User::factory()->create();

    grainOperationService()->markReady($operation);
    grainOperationService()->complete($operation->refresh(), $actor);

    $stock = InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $destination->getKey())
        ->sole();

    expect((float) $stock->on_hand_quantity)->toBe(12.75)
        ->and((float) $stock->available_quantity)->toBe(12.75)
        // 12.75 sacks of 25 kg. The weight is derived, never stored as a balance of its own.
        ->and($variant->weightFor((float) $stock->on_hand_quantity))->toBe(318.75);
});

it('keeps a fractional grain quantity exact across a delivery and a transfer', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    InventoryStock::factory()->for($variant)->for($source)->create([
        'on_hand_quantity' => '20.000',
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => '20.000',
    ]);
    // A grain still needs to be traceable to the sack it came from, even with no expiry date,
    // so both outbound lines below have to name this batch.
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
        'lot_number' => 'GRAIN-BATCH-1',
        'expires_at' => null,
        'on_hand_quantity' => '20.000',
        'reserved_quantity' => '0.000',
    ]);
    $actor = User::factory()->create();

    $delivery = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
    $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '3.333',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    grainOperationService()->markReady($delivery);
    grainOperationService()->complete($delivery->refresh(), $actor);

    $transfer = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $transfer->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '6.667',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    grainOperationService()->markReady($transfer);
    grainOperationService()->dispatch($transfer->refresh(), $actor);
    grainOperationService()->complete($transfer->refresh(), $actor);

    $sourceStock = InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $source->getKey())
        ->sole();
    $destinationStock = InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $destination->getKey())
        ->sole();

    // 20 − 3.333 − 6.667 = 10 exactly. Three decimal places are preserved, never truncated.
    expect((float) $sourceStock->on_hand_quantity)->toBe(10.0)
        ->and((float) $destinationStock->on_hand_quantity)->toBe(6.667)
        ->and(grainLotOnHand($lot, $source))->toBe(10.0)
        ->and(grainLotOnHand($lot, $destination))->toBe(6.667);
});

it('creates an unlabeled-expiry batch for a grain receipt, since it is still traceable by lot', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '12.500',
        'unit_id' => $variant->unit_id,
        'lot_number' => 'GRAIN-LOT-1',
    ]);
    $actor = User::factory()->create();

    grainOperationService()->markReady($operation);
    grainOperationService()->complete($operation->refresh(), $actor);

    $lot = InventoryLot::query()->where('product_variant_id', $variant->getKey())->sole();

    expect($lot->lot_number)->toBe('GRAIN-LOT-1')
        ->and($lot->expires_at)->toBeNull()
        ->and(grainLotOnHand($lot, $destination))->toBe(12.5)
        ->and((float) InventoryStock::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $destination->getKey())
            ->value('on_hand_quantity'))->toBe(12.5);
});

it('refuses a grain delivery line that names no batch', function (): void {
    $source = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    InventoryStock::factory()->for($variant)->for($source)->create(['available_quantity' => '10.000']);
    $operation = InventoryOperation::factory()->delivery()->create(['source_warehouse_id' => $source->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '4.000',
        'unit_id' => $variant->unit_id,
    ]);

    grainOperationService()->markReady($operation);
})->throws(DomainException::class);

it('rejects a grain quantity whose unit forbids decimals', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create([
        'unit_id' => Unit::factory()->whole()->create()->getKey(),
    ]);
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '2.500',
        'unit_id' => $variant->unit_id,
    ]);

    grainOperationService()->markReady($operation);
})->throws(DomainException::class);

it('reports a grain by weight and leaves other types without one', function (): void {
    $warehouse = Warehouse::factory()->create();
    $grain = ProductVariant::factory()->grain()->create(['net_weight' => 50]);
    $machine = ProductVariant::factory()->machine()->create();

    $grainStock = InventoryStock::factory()->for($grain)->for($warehouse)->create([
        'on_hand_quantity' => '4.000',
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => '4.000',
    ]);
    $machineStock = InventoryStock::factory()->for($machine)->for($warehouse)->create([
        'on_hand_quantity' => '4.000',
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => '4.000',
    ]);

    $formatter = app(InventoryReportFormatter::class);
    $headings = $formatter->headings(InventoryReportType::StockLevels, false);
    $weightColumn = array_search('Total weight', $headings, true);

    $grainRow = $formatter->values(InventoryReportType::StockLevels, $grainStock->load('productVariant.weightUnit', 'productVariant.product'), false);
    $machineRow = $formatter->values(InventoryReportType::StockLevels, $machineStock->load('productVariant.weightUnit', 'productVariant.product'), false);

    expect($weightColumn)->toBeInt()
        ->and($grainRow[$weightColumn])->toBe(200.0)
        // Null, not zero: a machine has no weight rather than weighing nothing.
        ->and($machineRow[$weightColumn])->toBeNull();
});

function grainLotOnHand(InventoryLot $lot, Warehouse $warehouse): float
{
    return (float) InventoryLotBalance::query()
        ->where('inventory_lot_id', $lot->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->where('stock_condition', StockCondition::Saleable->value)
        ->value('on_hand_base_quantity');
}
