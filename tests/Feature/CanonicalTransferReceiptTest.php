<?php

declare(strict_types=1);

use App\Data\Inventory\TransferReceiptCommand;
use App\Data\Inventory\TransferReceiptLine;
use App\Enums\OperationStage;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Enums\TransferDiscrepancyDisposition;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\ProductVariantUomService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('preserves one lot identity while a partial transfer moves custody between warehouse balances', function (): void {
    [$operation, $line, $source, $destination, $variant, $actor, $lot] = dispatchedCanonicalTransfer('10.000000');

    $received = app(InventoryOperationService::class)->receiveTransfer(
        $operation->refresh(),
        $actor,
        new TransferReceiptCommand([
            new TransferReceiptLine($line->getKey(), '4.000000'),
        ]),
    );

    $postedLine = $line->fresh();

    expect($received->stage)->toBe(OperationStage::PartiallyReceived)
        ->and(transferStock($variant, $source)->on_hand_quantity)->toBe('0.000000')
        ->and(transferStock($variant, $destination)->on_hand_quantity)->toBe('4.000000')
        ->and($postedLine->dispatched_base_quantity)->toBe('10.000000')
        ->and($postedLine->received_base_quantity)->toBe('4.000000')
        ->and($postedLine->source_inventory_lot_id)->toBe($lot->getKey())
        ->and($postedLine->destination_inventory_lot_id)->toBe($lot->getKey())
        ->and(InventoryLot::query()->canonical()->where('product_variant_id', $variant->getKey())->count())->toBe(1)
        ->and(lotSaleable($lot, $source))->toBe('0.000000')
        ->and(lotSaleable($lot, $destination))->toBe('4.000000')
        ->and(InventoryMovement::query()->where('source_id', $operation->getKey())->count())->toBe(3);
});

it('restores a cancellation discrepancy to the same source lot identity', function (): void {
    [$operation, $line, $source, $destination, $variant, $actor, $lot] = dispatchedCanonicalTransfer('10.000000');

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

    expect($received->isDone())->toBeTrue()
        ->and(transferStock($variant, $source)->on_hand_quantity)->toBe('7.000000')
        ->and(transferStock($variant, $destination)->on_hand_quantity)->toBe('3.000000')
        ->and(lotSaleable($lot, $source))->toBe('7.000000')
        ->and(lotSaleable($lot, $destination))->toBe('3.000000')
        ->and($line->fresh()->source_inventory_lot_id)->toBe($lot->getKey())
        ->and($line->fresh()->destination_inventory_lot_id)->toBe($lot->getKey())
        ->and(InventoryMovement::query()->where('source_id', $operation->getKey())->count())->toBe(4);
});

it('records shortage without inventing destination lot quantity', function (): void {
    [$operation, $line, $source, $destination, $variant, $actor, $lot] = dispatchedCanonicalTransfer('10.000000');

    $received = app(InventoryOperationService::class)->receiveTransfer(
        $operation->refresh(),
        $actor,
        new TransferReceiptCommand([
            new TransferReceiptLine(
                $line->getKey(),
                '4.000000',
                TransferDiscrepancyDisposition::Shortage,
                'Six containers were not received.',
            ),
        ]),
    );

    expect($received->isDone())->toBeTrue()
        ->and(lotSaleable($lot, $source))->toBe('0.000000')
        ->and(lotSaleable($lot, $destination))->toBe('4.000000')
        ->and($line->fresh()->discrepancy_disposition)->toBe(TransferDiscrepancyDisposition::Shortage);
});

it('preserves a transfer UOM snapshot on the same lot identity', function (): void {
    $piece = Unit::factory()->whole()->create(['code' => 'TRANSFER-PIECE', 'symbol' => 'TRP']);
    $box = Unit::factory()->whole()->create(['code' => 'TRANSFER-BOX', 'symbol' => 'TRB']);
    $variant = ProductVariant::factory()->grain()->create();

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
        ->and($line->fresh()->source_inventory_lot_id)->toBe($lot->getKey())
        ->and($line->fresh()->destination_inventory_lot_id)->toBe($lot->getKey())
        ->and(lotSaleable($lot, $destination))->toBe('200.000000')
        ->and($destinationMovement->transaction_quantity)->toBe('2.000000')
        ->and($destinationMovement->transaction_unit_id)->toBe($box->getKey())
        ->and($destinationMovement->conversion_factor_snapshot)->toBe('100.000000')
        ->and($destinationMovement->base_quantity_delta)->toBe('200.000000');
});

it('moves a serialized unit with its lot identity through transfer custody', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    InventoryStock::factory()->for($variant)->for($source)->create([
        'on_hand_quantity' => '1.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '1.000000',
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $source->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Saleable,
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
    $service->complete($transfer->refresh(), $actor);

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::Available)
        ->and($unit->warehouse_id)->toBe($destination->getKey())
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Warehouse);
});

/** @return array{InventoryOperation, InventoryOperationLine, Warehouse, Warehouse, ProductVariant, User, InventoryLot} */
function dispatchedCanonicalTransfer(string $quantity): array
{
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
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

    return [$operation, $line, $source, $destination, $variant, $actor, $lot];
}

function transferStock(ProductVariant $variant, Warehouse $warehouse): InventoryStock
{
    return InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->sole();
}

function lotSaleable(InventoryLot $lot, Warehouse $warehouse): string
{
    return (string) InventoryLotBalance::query()
        ->where('inventory_lot_id', $lot->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->where('stock_condition', StockCondition::Saleable->value)
        ->value('on_hand_base_quantity');
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

it('links transfer cancellation compensation to the original dispatch movement', function (): void {
    [$operation, $line, $source, , $variant, $actor, $lot] = dispatchedCanonicalTransfer('8.000000');

    $dispatchMovement = InventoryMovement::query()
        ->where('idempotency_key', sprintf(
            'inventory-operation-transfer:%d:%d:dispatch',
            $operation->getKey(),
            $line->getKey(),
        ))
        ->sole();

    $cancelled = app(InventoryOperationService::class)->cancel(
        $operation->refresh(),
        $actor,
        'Carrier never collected the transfer.',
    );

    $compensating = InventoryMovement::query()
        ->where('reversal_of_movement_id', $dispatchMovement->getKey())
        ->sole();

    expect($cancelled->isCanceled())->toBeTrue()
        ->and($dispatchMovement->refresh()->quantity)->toBe('-8.000000')
        ->and($dispatchMovement->reversal_of_movement_id)->toBeNull()
        ->and($compensating->movement_type)->toBe($dispatchMovement->movement_type)
        ->and($compensating->quantity)->toBe('8.000000')
        ->and($compensating->source_line_id)->toBe($line->getKey())
        ->and(transferStock($variant, $source)->on_hand_quantity)->toBe('8.000000')
        ->and(lotSaleable($lot, $source))->toBe('8.000000');
});

it('records non-serialized transfer shortage as explicit zero-quantity ledger evidence', function (): void {
    [$operation, $line, , $destination, $variant, $actor, $lot] = dispatchedCanonicalTransfer('10.000000');

    app(InventoryOperationService::class)->receiveTransfer(
        $operation->refresh(),
        $actor,
        new TransferReceiptCommand([
            new TransferReceiptLine(
                $line->getKey(),
                '4.000000',
                TransferDiscrepancyDisposition::Shortage,
                'Six units were missing at destination.',
            ),
        ]),
    );

    $evidence = InventoryMovement::query()
        ->where('idempotency_key', sprintf(
            'inventory-operation-transfer:%d:%d:discrepancy:%s',
            $operation->getKey(),
            $line->getKey(),
            TransferDiscrepancyDisposition::Shortage->value,
        ))
        ->sole();

    expect($evidence->quantity)->toBe('0.000000')
        ->and($evidence->base_quantity_delta)->toBeNull()
        ->and($evidence->notes)->toBe('Six units were missing at destination.')
        ->and($evidence->source_line_id)->toBe($line->getKey())
        ->and($line->refresh()->discrepancy_disposition)->toBe(TransferDiscrepancyDisposition::Shortage)
        ->and($line->discrepancy_reason)->toBe('Six units were missing at destination.')
        ->and(transferStock($variant, $destination)->on_hand_quantity)->toBe('4.000000')
        ->and(lotSaleable($lot, $destination))->toBe('4.000000');
});

it('links a line-level cancelled transfer discrepancy to its dispatch movement', function (): void {
    [$operation, $line, $source, $destination, $variant, $actor] = dispatchedCanonicalTransfer('10.000000');

    $dispatchMovement = InventoryMovement::query()
        ->where('idempotency_key', sprintf(
            'inventory-operation-transfer:%d:%d:dispatch',
            $operation->getKey(),
            $line->getKey(),
        ))
        ->sole();

    app(InventoryOperationService::class)->receiveTransfer(
        $operation->refresh(),
        $actor,
        new TransferReceiptCommand([
            new TransferReceiptLine(
                $line->getKey(),
                '3.000000',
                TransferDiscrepancyDisposition::Cancelled,
                'Seven units remained at the source.',
            ),
        ]),
    );

    $compensating = InventoryMovement::query()
        ->where('reversal_of_movement_id', $dispatchMovement->getKey())
        ->sole();

    expect($compensating->quantity)->toBe('7.000000')
        ->and(transferStock($variant, $source)->on_hand_quantity)->toBe('7.000000')
        ->and(transferStock($variant, $destination)->on_hand_quantity)->toBe('3.000000');
});

it('records a damaged in-transit discrepancy as explicit ledger evidence', function (): void {
    [$operation, $line, $source, $destination, $variant, $actor, $lot] = dispatchedCanonicalTransfer('6.000000');

    app(InventoryOperationService::class)->receiveTransfer(
        $operation->refresh(),
        $actor,
        new TransferReceiptCommand([
            new TransferReceiptLine(
                $line->getKey(),
                '2.000000',
                TransferDiscrepancyDisposition::Damaged,
                'Four units were damaged while in transit.',
            ),
        ]),
    );

    $evidence = InventoryMovement::query()
        ->where('idempotency_key', sprintf(
            'inventory-operation-transfer:%d:%d:discrepancy:%s',
            $operation->getKey(),
            $line->getKey(),
            TransferDiscrepancyDisposition::Damaged->value,
        ))
        ->sole();

    expect($evidence->quantity)->toBe('0.000000')
        ->and($evidence->notes)->toBe('Four units were damaged while in transit.')
        ->and($line->refresh()->discrepancy_disposition)->toBe(TransferDiscrepancyDisposition::Damaged)
        ->and($line->discrepancy_reason)->toBe('Four units were damaged while in transit.')
        ->and(transferStock($variant, $source)->on_hand_quantity)->toBe('0.000000')
        ->and(transferStock($variant, $destination)->on_hand_quantity)->toBe('2.000000')
        ->and(lotSaleable($lot, $destination))->toBe('2.000000');
});
