<?php

declare(strict_types=1);

use App\Data\Inventory\TransferReceiptCommand;
use App\Data\Inventory\TransferReceiptLine;
use App\Enums\OperationStage;
use App\Enums\TransferDiscrepancyDisposition;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\ProductVariantUomService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('posts only actual transfer receipts and keeps the remainder in transit', function (): void {
    [$operation, $line, $source, $destination, $variant, $actor] = dispatchedCanonicalTransfer('10.000000');

    $received = app(InventoryOperationService::class)->receiveTransfer(
        $operation->refresh(),
        $actor,
        new TransferReceiptCommand([
            new TransferReceiptLine($line->getKey(), '4.000000'),
        ]),
    );

    $sourceStock = transferStock($variant, $source);
    $destinationStock = transferStock($variant, $destination);
    $postedLine = $line->fresh();

    expect($received->stage)->toBe(OperationStage::PartiallyReceived)
        ->and($sourceStock->on_hand_quantity)->toBe('0.000000')
        ->and($destinationStock->on_hand_quantity)->toBe('4.000000')
        ->and($destinationStock->inTransitQuantity())->toBe(6.0)
        ->and($postedLine->dispatched_base_quantity)->toBe('10.000000')
        ->and($postedLine->received_base_quantity)->toBe('4.000000')
        ->and($postedLine->source_inventory_lot_id)->not->toBeNull()
        ->and($postedLine->destination_inventory_lot_id)->not->toBeNull()
        ->and(InventoryMovement::query()->where('source_id', $operation->getKey())->count())->toBe(2);
});

it('records a shortage without creating destination stock for the missing quantity', function (): void {
    [$operation, $line, $source, $destination, $variant, $actor] = dispatchedCanonicalTransfer('10.000000');

    $received = app(InventoryOperationService::class)->receiveTransfer(
        $operation->refresh(),
        $actor,
        new TransferReceiptCommand([
            new TransferReceiptLine(
                $line->getKey(),
                '4.000000',
                TransferDiscrepancyDisposition::Shortage,
                'Six containers were not received from the carrier.',
            ),
        ]),
    );

    $postedLine = $line->fresh();

    expect($received->isDone())->toBeTrue()
        ->and(transferStock($variant, $source)->on_hand_quantity)->toBe('0.000000')
        ->and(transferStock($variant, $destination)->on_hand_quantity)->toBe('4.000000')
        ->and($postedLine->received_base_quantity)->toBe('4.000000')
        ->and($postedLine->discrepancy_disposition)->toBe(TransferDiscrepancyDisposition::Shortage)
        ->and($postedLine->discrepancy_reason)->toBe('Six containers were not received from the carrier.')
        ->and(InventoryMovement::query()->where('source_id', $operation->getKey())->count())->toBe(2);
});

it('posts a cancellation discrepancy as a compensating source receipt', function (): void {
    [$operation, $line, $source, $destination, $variant, $actor] = dispatchedCanonicalTransfer('10.000000');

    $received = app(InventoryOperationService::class)->receiveTransfer(
        $operation->refresh(),
        $actor,
        new TransferReceiptCommand([
            new TransferReceiptLine(
                $line->getKey(),
                '3.000000',
                TransferDiscrepancyDisposition::Cancelled,
                'The remaining containers never left the source dock.',
            ),
        ]),
    );

    $sourceLot = InventoryLot::query()->where('warehouse_id', $source->getKey())->sole();
    $destinationLot = InventoryLot::query()->where('warehouse_id', $destination->getKey())->sole();

    expect($received->isDone())->toBeTrue()
        ->and(transferStock($variant, $source)->on_hand_quantity)->toBe('7.000000')
        ->and(transferStock($variant, $destination)->on_hand_quantity)->toBe('3.000000')
        ->and($sourceLot->on_hand_quantity)->toBe('7.000000')
        ->and($destinationLot->on_hand_quantity)->toBe('3.000000')
        ->and(InventoryMovement::query()->where('source_id', $operation->getKey())->count())->toBe(3);
});

it('preserves a transfer UOM snapshot while receiving an actual partial quantity', function (): void {
    $piece = Unit::factory()->whole()->create([
        'code' => 'TRANSFER-PIECE',
        'symbol' => 'TRP',
    ]);
    $box = Unit::factory()->whole()->create([
        'code' => 'TRANSFER-BOX',
        'symbol' => 'TRB',
    ]);
    $variant = ProductVariant::factory()->create();
    app(ProductVariantUomService::class)->sync($variant, [
        canonicalTransferUomDefinition($piece, isBase: true, factor: '1'),
        canonicalTransferUomDefinition($box, factor: '100'),
    ]);

    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $actor = User::factory()->create();
    InventoryStock::factory()->for($variant)->for($source)->create([
        'on_hand_quantity' => '500.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '500.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
        'on_hand_quantity' => '500.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $operation = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $line = $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '5',
        'unit_id' => $box->getKey(),
        'inventory_lot_id' => $lot->getKey(),
    ]);

    $service = app(InventoryOperationService::class);
    $service->markReady($operation, $actor);
    $service->dispatch($operation->refresh(), $actor);
    $service->receiveTransfer(
        $operation->refresh(),
        $actor,
        new TransferReceiptCommand([new TransferReceiptLine($line->getKey(), '2')]),
    );

    $destinationMovement = InventoryMovement::query()
        ->where('source_line_id', $line->getKey())
        ->where('warehouse_id', $destination->getKey())
        ->sole();

    expect($line->fresh()->base_quantity)->toBe('500.000000')
        ->and($line->fresh()->received_base_quantity)->toBe('200.000000')
        ->and(transferStock($variant, $destination)->on_hand_quantity)->toBe('200.000000')
        ->and($destinationMovement->transaction_quantity)->toBe('2.000000')
        ->and($destinationMovement->transaction_unit_id)->toBe($box->getKey())
        ->and($destinationMovement->conversion_factor_snapshot)->toBe('100.000000')
        ->and($destinationMovement->base_quantity_delta)->toBe('200.000000');
});

/** @return array{0: InventoryOperation, 1: InventoryOperationLine, 2: Warehouse, 3: Warehouse, 4: ProductVariant, 5: User} */
function dispatchedCanonicalTransfer(string $quantity): array
{
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $actor = User::factory()->create();
    InventoryStock::factory()->for($variant)->for($source)->create([
        'on_hand_quantity' => $quantity,
        'reserved_quantity' => '0.000000',
        'available_quantity' => $quantity,
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($source)->create([
        'on_hand_quantity' => $quantity,
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $operation = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $line = $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => $quantity,
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    $service = app(InventoryOperationService::class);
    $service->markReady($operation, $actor);
    $service->dispatch($operation->refresh(), $actor);

    return [$operation, $line, $source, $destination, $variant, $actor];
}

function transferStock(ProductVariant $variant, Warehouse $warehouse): InventoryStock
{
    return InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->sole();
}

/** @return array<string, bool|int|string> */
function canonicalTransferUomDefinition(Unit $unit, bool $isBase = false, string $factor = '1'): array
{
    return [
        'unit_id' => $unit->getKey(),
        'is_base' => $isBase,
        'is_purchase' => true,
        'is_sale' => true,
        'is_display' => $isBase,
        'factor_to_base' => $factor,
        'rounding_increment' => '1',
        'permits_cross_family_conversion' => false,
        'is_active' => true,
    ];
}


it('moves serialized custody through in-transit and destination warehouse states canonically', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    InventoryStock::factory()->for($variant)->for($source)->create([
        'on_hand_quantity' => '1.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '1.000000',
    ]);
    $unit = \App\Models\SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $source->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
    ]);
    $transfer = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $transfer->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '1',
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);
    $actor = User::factory()->create();
    $service = app(InventoryOperationService::class);

    $service->markReady($transfer, $actor);
    $service->dispatch($transfer->refresh(), $actor);

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::InTransit)
        ->and($unit->warehouse_id)->toBeNull()
        ->and($unit->custody_type)->toBe(SerializedCustodyType::InTransit);

    $service->complete($transfer->refresh(), $actor);

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::Available)
        ->and($unit->warehouse_id)->toBe($destination->getKey())
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Warehouse)
        ->and($unit->custody_reference_type)->toBe('warehouse')
        ->and($unit->custody_reference_id)->toBe($destination->getKey());
});
