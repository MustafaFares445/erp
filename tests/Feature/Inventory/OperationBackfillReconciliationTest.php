<?php

declare(strict_types=1);

use App\Models\InventoryOperation;
use App\Models\InventoryReceipt;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationBackfiller;
use App\Services\Inventory\OperationBackfillReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// R-002, data-model.md §10: the highest-risk migration in this feature. Every legacy receipt and
// transfer must be represented by an equivalent operation, and in-transit totals must agree under
// both derivations. Balances and the movement ledger are asserted unchanged by construction: the
// backfiller never writes to inventory_stocks or inventory_movements (verified below by literal
// row-count equality across the backfill call).

it('backfills a confirmed receipt into a Done operation with matching lines and no stock-table writes', function (): void {
    $receipt = InventoryReceipt::factory()->create();
    $variant = ProductVariant::factory()->create();
    $receipt->items()->create(['product_variant_id' => $variant->getKey(), 'unit_id' => $variant->unit_id, 'quantity' => '4.000']);
    $receipt->forceFill(['status' => 'confirmed', 'receipt_number' => 'REC-000001'])->save();

    $stockCountBefore = InventoryStock::query()->count();

    app(InventoryOperationBackfiller::class)->backfill();

    $operation = InventoryOperation::query()->where('legacy_receipt_id', $receipt->getKey())->firstOrFail();

    expect($operation->isDone())->toBeTrue()
        ->and($operation->destination_warehouse_id)->toBe($receipt->warehouse_id)
        ->and((float) $operation->lines()->sum('quantity'))->toBe(4.0)
        ->and(InventoryStock::query()->count())->toBe($stockCountBefore);

    $report = app(OperationBackfillReconciler::class)->reconcile();

    expect($report)->toBe([]);
});

it('backfills a draft receipt into a Draft operation', function (): void {
    $receipt = InventoryReceipt::factory()->create();
    $variant = ProductVariant::factory()->create();
    $receipt->items()->create(['product_variant_id' => $variant->getKey(), 'unit_id' => $variant->unit_id, 'quantity' => '2.000']);

    app(InventoryOperationBackfiller::class)->backfill();

    $operation = InventoryOperation::query()->where('legacy_receipt_id', $receipt->getKey())->firstOrFail();

    expect($operation->isDraft())->toBeTrue()
        ->and(app(OperationBackfillReconciler::class)->reconcile())->toBe([]);
});

it('backfills a dispatched transfer into an InTransit operation whose in-transit total agrees with the legacy derivation', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $transfer = StockTransfer::factory()->dispatched()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '3.000']);

    app(InventoryOperationBackfiller::class)->backfill();

    $operation = InventoryOperation::query()->where('legacy_transfer_id', $transfer->getKey())->firstOrFail();

    expect($operation->isInTransit())->toBeTrue()
        ->and($operation->source_warehouse_id)->toBe($from->getKey())
        ->and($operation->destination_warehouse_id)->toBe($to->getKey())
        ->and((float) $operation->lines()->sum('quantity'))->toBe(3.0);

    expect(app(OperationBackfillReconciler::class)->reconcile())->toBe([]);
});

it('backfills a received transfer into a Done operation', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $transfer = StockTransfer::factory()->received()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '2.000']);

    app(InventoryOperationBackfiller::class)->backfill();

    $operation = InventoryOperation::query()->where('legacy_transfer_id', $transfer->getKey())->firstOrFail();

    expect($operation->isDone())->toBeTrue()
        ->and(app(OperationBackfillReconciler::class)->reconcile())->toBe([]);
});

it('backfills one operation line per serialized unit on a receipt item, matching the receiving flow', function (): void {
    $receipt = InventoryReceipt::factory()->create();
    $variant = ProductVariant::factory()->create(['track_serials' => true]);
    $item = $receipt->items()->create(['product_variant_id' => $variant->getKey(), 'unit_id' => $variant->unit_id, 'quantity' => '2.000']);
    SerializedInventoryUnit::factory()->count(2)->create(['inventory_receipt_item_id' => $item->getKey(), 'product_variant_id' => $variant->getKey()]);

    app(InventoryOperationBackfiller::class)->backfill();

    $operation = InventoryOperation::query()->where('legacy_receipt_id', $receipt->getKey())->firstOrFail();

    expect($operation->lines()->count())->toBe(2)
        ->and($operation->lines()->whereNotNull('serialized_inventory_unit_id')->count())->toBe(2)
        ->and(app(OperationBackfillReconciler::class)->reconcile())->toBe([]);
});

it('reports a discrepancy when a legacy receipt has no corresponding backfilled operation', function (): void {
    InventoryReceipt::factory()->create();

    $report = app(OperationBackfillReconciler::class)->reconcile();

    expect($report)->not->toBe([])
        ->and($report[0])->toContain('has no backfilled operation');
});

it('reports a discrepancy when a legacy transfer has no corresponding backfilled operation', function (): void {
    $transfer = StockTransfer::factory()->dispatched()->create();

    $report = app(OperationBackfillReconciler::class)->reconcile();

    expect($report)->toContain(sprintf('Transfer #%d has no backfilled operation.', $transfer->getKey()));
});

it('is idempotent: running backfill twice does not duplicate operations', function (): void {
    $receipt = InventoryReceipt::factory()->create();
    ProductVariant::factory()->create();

    $backfiller = app(InventoryOperationBackfiller::class);
    $backfiller->backfill();
    $backfiller->backfill();

    expect(InventoryOperation::query()->where('legacy_receipt_id', $receipt->getKey())->count())->toBe(1);
});

it('skips legacy lines that have no usable unit identity', function (): void {
    $receipt = InventoryReceipt::factory()->create();
    $variant = ProductVariant::factory()->create(['unit_id' => null]);
    $receipt->items()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => null,
        'quantity' => '2.000',
    ]);

    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '1.000']);

    app(InventoryOperationBackfiller::class)->backfill();

    expect(InventoryOperation::query()->where('legacy_receipt_id', $receipt->getKey())->firstOrFail()->lines)->toBeEmpty()
        ->and(InventoryOperation::query()->where('legacy_transfer_id', $transfer->getKey())->firstOrFail()->lines)->toBeEmpty();
});

it('reports receipt, transfer, and in-transit reconciliation discrepancies', function (): void {
    $receipt = InventoryReceipt::factory()->create();
    $variant = ProductVariant::factory()->create();
    $receipt->items()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => '2.000',
    ]);
    InventoryOperation::factory()->receipt()->create([
        'legacy_receipt_id' => $receipt->getKey(),
        'destination_warehouse_id' => Warehouse::factory(),
    ]);

    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $transfer = StockTransfer::factory()->dispatched()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '3.000']);
    InventoryOperation::factory()->internalTransfer()->inTransit()->create([
        'legacy_transfer_id' => $transfer->getKey(),
        'source_warehouse_id' => Warehouse::factory(),
        'destination_warehouse_id' => Warehouse::factory(),
    ]);

    $report = app(OperationBackfillReconciler::class)->reconcile();

    expect($report)->toHaveCount(6)
        ->and(collect($report)->filter(fn (string $message): bool => str_contains($message, 'warehouse mismatch'))->count())->toBe(2)
        ->and(collect($report)->filter(fn (string $message): bool => str_contains($message, 'quantity'))->count())->toBe(2)
        ->and(collect($report)->filter(fn (string $message): bool => str_contains($message, 'legacy items'))->count())->toBe(1)
        ->and(collect($report)->filter(fn (string $message): bool => str_contains($message, 'In-transit total'))->count())->toBe(1);
});
