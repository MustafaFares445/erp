<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\MovementType;
use App\Enums\TransferStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The **only** code path that mutates stock as a result of a transfer
 * (constitution Principle III; FR-008). The Filament layer never touches
 * {@see InventoryStock}/{@see InventoryMovement} directly — enforced by the
 * FI-0 architecture guard in tests/Unit/ArchTest.php (research D6) — so
 * every write physically has to flow through here.
 *
 * @see /specs/004-stock-transfers/contracts/transfer-service.md
 */
final readonly class StockTransferService
{
    public function __construct(
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Apply a draft transfer: check the source has enough available stock
     * for every line (summed per variant across duplicate lines), then for
     * each line record a negative movement out of the source and a positive
     * movement into the destination, updating both balances by exactly that
     * line's quantity, assign the transfer number, mark the document
     * confirmed, and write one audit record — atomically. Throws on any
     * domain violation, leaving no partial state.
     *
     * @throws DomainException invalid state / same or inactive warehouse / insufficient stock
     */
    public function confirm(StockTransfer $transfer, User $actor): void
    {
        DB::transaction(function () use ($transfer, $actor): void {
            /** @var StockTransfer $locked */
            $locked = StockTransfer::query()
                ->with(['fromWarehouse', 'toWarehouse'])
                ->lockForUpdate()
                ->findOrFail($transfer->getKey());

            if ($locked->status !== TransferStatus::Draft) {
                throw new DomainException(__('admin.inventory.transfer.errors.not_draft'));
            }

            if ($locked->from_warehouse_id === $locked->to_warehouse_id) {
                throw new DomainException(__('admin.inventory.transfer.errors.same_warehouse'));
            }

            $fromWarehouse = $locked->fromWarehouse;
            $toWarehouse = $locked->toWarehouse;

            if (! $fromWarehouse instanceof Warehouse || ! $fromWarehouse->is_active
                || ! $toWarehouse instanceof Warehouse || ! $toWarehouse->is_active) {
                throw new DomainException(__('admin.inventory.transfer.errors.inactive_warehouse'));
            }

            $items = $locked->items()->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new DomainException(__('admin.inventory.transfer.errors.no_items'));
            }

            $this->assertSufficientAvailability($items, $locked->from_warehouse_id);

            $balancesBefore = $this->currentBalances($items, $locked->from_warehouse_id, $locked->to_warehouse_id);

            $recordMovement = function (StockTransferItem $item, int $warehouseId, float $quantity) use ($locked, $actor): void {
                InventoryMovement::query()->forceCreate([
                    'product_variant_id' => $item->product_variant_id,
                    'warehouse_id' => $warehouseId,
                    'movement_type' => MovementType::Transfer,
                    'quantity' => $quantity,
                    'source_type' => 'transfer',
                    'source_id' => $locked->getKey(),
                    'status' => 'confirmed',
                    'created_by' => $actor->getKey(),
                    'notes' => $locked->notes,
                ]);
            };

            foreach ($items as $item) {
                $this->applyOut($item, $locked->from_warehouse_id);
                $this->applyIn($item, $locked->to_warehouse_id);

                $recordMovement($item, $locked->from_warehouse_id, -(float) $item->quantity);
                $recordMovement($item, $locked->to_warehouse_id, (float) $item->quantity);
            }

            $balancesAfter = $this->currentBalances($items, $locked->from_warehouse_id, $locked->to_warehouse_id);

            $transferNumber = $this->nextTransferNumber();

            $locked->forceFill([
                'transfer_number' => $transferNumber,
                'status' => TransferStatus::Confirmed,
                'updated_by' => $actor->getKey(),
            ])->saveQuietly();

            $this->auditLogger->log(
                action: 'inventory.transfer.confirmed',
                entity: $locked,
                oldValues: ['status' => 'draft', 'balances' => $balancesBefore],
                newValues: ['status' => 'confirmed', 'transfer_number' => $transferNumber, 'balances' => $balancesAfter],
                actor: $actor,
                sourceChannel: 'dashboard',
            );
        });
    }

    /**
     * Groups the transfer's item lines by variant (duplicates summed, per
     * FR-009a) and guards that the source's *available* balance covers each
     * variant's total requested quantity (research D4).
     *
     * @param  Collection<int, StockTransferItem>  $items
     *
     * @throws DomainException when any variant's summed requirement exceeds what the source has available
     */
    private function assertSufficientAvailability(Collection $items, int $fromWarehouseId): void
    {
        $requiredByVariant = $items
            ->groupBy('product_variant_id')
            ->map(function (Collection $lines): float {
                $sum = $lines->sum('quantity');

                return is_numeric($sum) ? (float) $sum : 0.0;
            });

        foreach ($requiredByVariant as $productVariantId => $required) {
            $available = InventoryStock::query()
                ->where('product_variant_id', $productVariantId)
                ->where('warehouse_id', $fromWarehouseId)
                ->lockForUpdate()
                ->value('available_quantity');

            $available = is_numeric($available) ? (float) $available : 0.0;

            if ($available < $required) {
                throw new DomainException(__('admin.inventory.transfer.errors.insufficient_stock'));
            }
        }
    }

    /**
     * Decrements the source `(variant, warehouse)` balance by the line
     * quantity. Availability was already verified in aggregate by
     * {@see self::assertSufficientAvailability()}, so a missing row here
     * cannot occur for a satisfiable line.
     */
    private function applyOut(StockTransferItem $item, int $warehouseId): void
    {
        $stock = InventoryStock::query()
            ->where('product_variant_id', $item->product_variant_id)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->firstOrFail();

        $newOnHand = (float) $stock->on_hand_quantity - (float) $item->quantity;

        $stock->on_hand_quantity = $newOnHand;
        $stock->available_quantity = $newOnHand - (float) $stock->reserved_quantity;
        $stock->save();
    }

    /**
     * Increments the destination `(variant, warehouse)` balance by the line
     * quantity, establishing the row at zero first if it does not yet exist
     * (FR-012).
     */
    private function applyIn(StockTransferItem $item, int $warehouseId): void
    {
        $stock = InventoryStock::query()
            ->where('product_variant_id', $item->product_variant_id)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if ($stock instanceof InventoryStock) {
            $newOnHand = (float) $stock->on_hand_quantity + (float) $item->quantity;
            $stock->on_hand_quantity = $newOnHand;
            $stock->available_quantity = $newOnHand - (float) $stock->reserved_quantity;
            $stock->save();

            return;
        }

        InventoryStock::query()->forceCreate([
            'product_variant_id' => $item->product_variant_id,
            'warehouse_id' => $warehouseId,
            'on_hand_quantity' => (float) $item->quantity,
            'reserved_quantity' => 0,
            'available_quantity' => (float) $item->quantity,
        ]);
    }

    /**
     * Snapshots the on-hand balance for every variant on the transfer at
     * both warehouses, for the confirmation audit's before/after payload.
     *
     * @param  Collection<int, StockTransferItem>  $items
     * @return array<int, array<string, float>>
     */
    private function currentBalances(Collection $items, int $fromWarehouseId, int $toWarehouseId): array
    {
        $variantIds = $items->pluck('product_variant_id')->unique()->values();

        $balances = [];

        foreach ($variantIds as $productVariantId) {
            // @codeCoverageIgnoreStart
            // Unreachable in practice: product_variant_id is a NOT NULL FK on
            // stock_transfer_items, so every plucked value is a real integer.
            // The guard exists only to satisfy static analysis (pluck()'s
            // return type is untyped mixed).
            if (! is_numeric($productVariantId)) {
                continue;
            }

            // @codeCoverageIgnoreEnd

            $productVariantId = (int) $productVariantId;

            $fromOnHand = InventoryStock::query()
                ->where('product_variant_id', $productVariantId)
                ->where('warehouse_id', $fromWarehouseId)
                ->value('on_hand_quantity');

            $toOnHand = InventoryStock::query()
                ->where('product_variant_id', $productVariantId)
                ->where('warehouse_id', $toWarehouseId)
                ->value('on_hand_quantity');

            $balances[(string) $productVariantId] = [
                'from' => is_numeric($fromOnHand) ? (float) $fromOnHand : 0.0,
                'to' => is_numeric($toOnHand) ? (float) $toOnHand : 0.0,
            ];
        }

        return $balances;
    }

    /**
     * `TRF-` + zero-padded sequential, derived from the locked max existing
     * number within the transaction (research D3). Zero-padded fixed-width
     * numbers sort identically as strings and numerically, so a plain SQL
     * `MAX()` is sufficient and lets the row lock scope to the rows involved.
     */
    private function nextTransferNumber(): string
    {
        $maxNumber = StockTransfer::query()
            ->whereNotNull('transfer_number')
            ->lockForUpdate()
            ->max('transfer_number');

        $nextSequence = is_string($maxNumber) ? ((int) mb_substr($maxNumber, 4) + 1) : 1;

        return sprintf('TRF-%06d', $nextSequence);
    }
}
