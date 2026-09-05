<?php

declare(strict_types=1);

use App\Data\Inventory\TransferReceiptCommand;
use App\Data\Inventory\TransferReceiptLine;
use App\Enums\ConditionChangeReason;
use App\Enums\InventoryCorrectionStatus;
use App\Enums\MovementType;
use App\Enums\OperationStage;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCorrectionService;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('corrects a wrong-destination transfer to the right warehouse in one transaction without ever over- or under-stating either warehouse', function (): void {
    [$transfer, $transferLine, , $wrongDestination, $variant, , $actor] = completedTransferForCorrection('8.000000');
    $correctDestination = Warehouse::factory()->create();

    $service = app(InventoryCorrectionService::class);
    $correction = $service->createTransferCorrection(
        $actor,
        $transfer,
        ConditionChangeReason::Other,
        'Transfer was keyed to the wrong destination warehouse.',
        (int) $correctDestination->getKey(),
    );
    $line = $service->addTransferLine($correction, $transferLine, '8.000000');

    // Lock probe: every intermediate write this correction makes to either warehouse's stock
    // row, observed as it happens inside the same transaction, must stay within [0, total] — the
    // total quantity being corrected never appears negative (understated) at the warehouse
    // losing it, nor exceeds the total (overstated, i.e. duplicated) at the warehouse gaining it.
    $probes = [];

    DB::listen(function (object $query) use (&$probes, $variant, $wrongDestination, $correctDestination): void {
        $sql = mb_strtolower((string) $query->sql);

        if (! str_starts_with($sql, 'update') || ! str_contains($sql, 'inventory_stocks')) {
            return;
        }

        $wrongQuantity = InventoryStock::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $wrongDestination->getKey())
            ->value('on_hand_quantity');
        $correctQuantity = InventoryStock::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $correctDestination->getKey())
            ->value('on_hand_quantity');

        $probes[] = [
            'wrong' => (float) ($wrongQuantity ?? 0),
            'correct' => (float) ($correctQuantity ?? 0),
        ];
    });

    $posted = $service->post($correction, $actor);

    expect($probes)->not->toBeEmpty();

    foreach ($probes as $probe) {
        expect($probe['wrong'])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(8.0)
            ->and($probe['correct'])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(8.0);
    }

    $removeMovement = InventoryMovement::query()
        ->where('source_type', 'inventory_correction')
        ->where('source_id', $correction->getKey())
        ->where('warehouse_id', $wrongDestination->getKey())
        ->sole();
    $addMovement = InventoryMovement::query()
        ->where('source_type', 'inventory_correction')
        ->where('source_id', $correction->getKey())
        ->where('warehouse_id', $correctDestination->getKey())
        ->sole();
    $originalReceiveMovement = InventoryMovement::query()
        ->where('source_type', 'inventory_operation')
        ->where('source_id', $transfer->getKey())
        ->where('source_line_id', $transferLine->getKey())
        ->where('movement_type', MovementType::Transfer->value)
        ->where('warehouse_id', $wrongDestination->getKey())
        ->sole();

    expect($posted->status)->toBe(InventoryCorrectionStatus::Posted)
        ->and((float) transferCorrectionStock($variant, $wrongDestination)->on_hand_quantity)->toBe(0.0)
        ->and((float) transferCorrectionStock($variant, $correctDestination)->on_hand_quantity)->toBe(8.0)
        ->and($removeMovement->movement_type)->toBe(MovementType::Correction)
        ->and($removeMovement->quantity)->toBe('-8.000000')
        ->and($removeMovement->reversal_of_movement_id)->toBe($originalReceiveMovement->getKey())
        ->and($addMovement->movement_type)->toBe(MovementType::Correction)
        ->and($addMovement->quantity)->toBe('8.000000')
        ->and($addMovement->reversal_of_movement_id)->toBeNull()
        ->and($line->refresh()->posted_inventory_movement_id)->toBe($removeMovement->getKey())
        ->and($line->posted_base_quantity)->toBe('8.000000');
});

it('refuses a transfer correction on a partially received transfer, pointing at the shortage workflow', function (): void {
    [$transfer, $transferLine, , , , , $actor] = completedTransferForCorrection('10.000000', complete: false);

    $operations = app(InventoryOperationService::class);
    $operations->receiveTransfer(
        $transfer->refresh(),
        $actor,
        new TransferReceiptCommand([
            new TransferReceiptLine(
                operationLineId: (int) $transferLine->getKey(),
                receivedTransactionQuantity: '6.000000',
            ),
        ]),
    );

    expect($transfer->refresh()->stage)->toBe(OperationStage::PartiallyReceived);

    expect(fn () => app(InventoryCorrectionService::class)->createTransferCorrection(
        $actor,
        $transfer->refresh(),
        ConditionChangeReason::Other,
        'Attempt to correct a still partially received transfer.',
    ))->toThrow(DomainException::class, 'shortage workflow');
});

/**
 * Builds a completed receipt into a source warehouse, then an internal transfer of the full
 * received quantity from that source into a "wrong" destination warehouse. When `$complete` is
 * true (the default) the transfer is driven all the way to `Done` via a single full transfer
 * receipt; when false, it is left `InTransit` for the caller to drive further (e.g. a partial
 * receipt).
 *
 * @return array{
 *   InventoryOperation,
 *   InventoryOperationLine,
 *   Warehouse,
 *   Warehouse,
 *   ProductVariant,
 *   InventoryLot,
 *   User
 * }
 */
function completedTransferForCorrection(string $quantity, bool $complete = true): array
{
    $source = Warehouse::factory()->create();
    $wrongDestination = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();

    $receipt = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $source->getKey(),
        'supplier_id' => $supplier->getKey(),
    ]);
    $receiptLine = $receipt->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => $quantity,
        'unit_id' => $variant->unit_id,
        'lot_number' => 'TRANSFER-CORRECTION-LOT',
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($receipt, $actor);
    $operations->complete($receipt->refresh(), $actor);

    $lot = InventoryLot::query()->findOrFail($receiptLine->refresh()->inventory_lot_id);

    $transfer = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $wrongDestination->getKey(),
    ]);
    $transferLine = $transfer->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => $quantity,
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    $operations->markReady($transfer, $actor);
    $operations->dispatch($transfer->refresh(), $actor);

    if ($complete) {
        $operations->complete($transfer->refresh(), $actor);
    }

    return [
        $transfer->refresh(),
        $transferLine->refresh(),
        $source,
        $wrongDestination,
        $variant,
        $lot,
        $actor,
    ];
}

function transferCorrectionStock(ProductVariant $variant, Warehouse $warehouse): InventoryStock
{
    return InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->sole();
}
