<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryPermission;
use App\Enums\ProductType;
use App\Models\InventoryLot;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owns the lifecycle of an {@see InventoryLot}: creating one when expiry-tracked goods arrive,
 * consuming from one when they leave, and reserving or releasing in between.
 *
 * Before this class existed, lots were write-only — created by the legacy receiving service and
 * never decremented by any outbound path, so lot balances drifted permanently away from the
 * stock they were supposed to describe, and "do not ship expired stock" was unenforceable
 * because nothing recorded which lot had left. Every lot balance change goes through here.
 *
 * Lot quantities intentionally mirror, and never replace, `inventory_stocks`. The balance grain
 * stays product variant + warehouse (A-001); a lot is a *breakdown* of that balance by expiry,
 * so {@see InventoryBalanceService} remains the only writer of the authoritative figure.
 */
final readonly class InventoryLotService
{
    public function __construct(
        private InventoryAlertService $inventoryAlertService,
    ) {}

    /**
     * Records the lot an inbound line describes.
     *
     * Lots are keyed on variant + warehouse + lot number + expiry, so receiving the same lot
     * twice tops up one row rather than fragmenting the batch across duplicates.
     *
     * @throws DomainException
     */
    public function receive(InventoryOperationLine $line, ProductVariant $variant, int $warehouseId): ?InventoryLot
    {
        $type = $variant->productType();

        if (! $type instanceof ProductType || ! $type->tracksBatches()) {
            return null;
        }

        $expiresAt = $line->expires_at;

        if ($type->tracksExpiry() && $expiresAt === null) {
            throw new DomainException(__('admin.inventory.product_type.errors.expiry_required'));
        }

        $query = InventoryLot::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouseId)
            ->where('lot_number', $line->lot_number);

        if ($expiresAt === null) {
            $query->whereNull('expires_at');
        } else {
            $query->whereDate('expires_at', $expiresAt->toDateString());
        }

        $lot = $query->lockForUpdate()->first();

        if ($lot instanceof InventoryLot) {
            $lot->forceFill([
                'on_hand_quantity' => round((float) $lot->on_hand_quantity + (float) $line->quantity, 3),
            ])->save();
        } else {
            $lot = InventoryLot::query()->create([
                'product_variant_id' => $variant->getKey(),
                'warehouse_id' => $warehouseId,
                'lot_number' => $line->lot_number,
                'expires_at' => $expiresAt,
                'on_hand_quantity' => $line->quantity,
                'reserved_quantity' => 0,
            ]);
        }

        $line->forceFill(['inventory_lot_id' => $lot->getKey()])->save();
        $this->inventoryAlertService->syncExpiry($lot);

        return $lot->refresh();
    }

    /**
     * Draws the line's quantity out of the lot it names.
     *
     * `$allowExpired` is the escape hatch for an actor holding
     * {@see InventoryPermission::ExpiredStockOverride}; using it is recorded as both
     * an alert and an audit entry, so shipping expired goods is always a traceable decision
     * rather than a silent one.
     *
     * @throws DomainException
     */
    public function consume(
        InventoryOperationLine $line,
        ProductVariant $variant,
        int $warehouseId,
        ?User $actor,
        bool $allowExpired = false,
    ): ?InventoryLot {
        if ($variant->productType()?->tracksBatches() !== true) {
            if ($line->inventory_lot_id !== null) {
                throw new DomainException(__('admin.inventory.lot.errors.not_applicable'));
            }

            return null;
        }

        $lot = $this->lockNamedLot($line, $variant, $warehouseId);
        $quantity = (float) $line->quantity;

        $this->assertNotExpired($lot, $actor, $allowExpired);

        if ($lot->availableQuantity() + (float) $lot->reserved_quantity < $quantity) {
            throw new DomainException(__('admin.inventory.lot.errors.insufficient_quantity', [
                'lot' => $this->describe($lot),
            ]));
        }

        $lot->forceFill([
            'on_hand_quantity' => round((float) $lot->on_hand_quantity - $quantity, 3),
            'reserved_quantity' => round(max(0.0, (float) $lot->reserved_quantity - $quantity), 3),
        ])->save();

        $this->inventoryAlertService->syncExpiry($lot->refresh());

        return $lot;
    }

    /**
     * Holds the line's quantity against its lot when an outbound operation becomes Ready, so a
     * second operation cannot commit the same batch.
     *
     * @throws DomainException
     */
    public function reserve(InventoryOperationLine $line, ProductVariant $variant, int $warehouseId, ?User $actor, bool $allowExpired = false): ?InventoryLot
    {
        if ($variant->productType()?->tracksBatches() !== true) {
            return null;
        }

        $lot = $this->lockNamedLot($line, $variant, $warehouseId);
        $quantity = (float) $line->quantity;

        $this->assertNotExpired($lot, $actor, $allowExpired);

        if ($lot->availableQuantity() < $quantity) {
            throw new DomainException(__('admin.inventory.lot.errors.insufficient_quantity', [
                'lot' => $this->describe($lot),
            ]));
        }

        $lot->forceFill([
            'reserved_quantity' => round((float) $lot->reserved_quantity + $quantity, 3),
        ])->save();

        return $lot->refresh();
    }

    /** Returns a reservation to the lot when an operation is cancelled. */
    public function release(InventoryOperationLine $line, ProductVariant $variant): ?InventoryLot
    {
        if ($variant->productType()?->tracksBatches() !== true || $line->inventory_lot_id === null) {
            return null;
        }

        $lot = InventoryLot::query()->lockForUpdate()->find($line->inventory_lot_id);

        if (! $lot instanceof InventoryLot) {
            return null;
        }

        $lot->forceFill([
            'reserved_quantity' => round(max(0.0, (float) $lot->reserved_quantity - (float) $line->quantity), 3),
        ])->save();

        return $lot->refresh();
    }

    /**
     * Restores a lot after an in-transit transfer is cancelled — the mirror of
     * {@see self::consume()}, so cancelling never leaves a lot short of the stock it describes.
     */
    public function restore(InventoryOperationLine $line, ProductVariant $variant): ?InventoryLot
    {
        if ($variant->productType()?->tracksBatches() !== true || $line->inventory_lot_id === null) {
            return null;
        }

        $lot = InventoryLot::query()->lockForUpdate()->find($line->inventory_lot_id);

        if (! $lot instanceof InventoryLot) {
            return null;
        }

        $lot->forceFill([
            'on_hand_quantity' => round((float) $lot->on_hand_quantity + (float) $line->quantity, 3),
        ])->save();

        $this->inventoryAlertService->syncExpiry($lot->refresh());

        return $lot;
    }

    /**
     * The lot an operator should reach for first: earliest expiry, then oldest — first-expired,
     * first-out. Used to pre-select a lot in the line editor rather than to decide silently on
     * the operator's behalf.
     *
     * @return Collection<int, InventoryLot>
     */
    public function availableLots(int $productVariantId, int $warehouseId, bool $includeExpired = false): Collection
    {
        return InventoryLot::query()
            ->where('product_variant_id', $productVariantId)
            ->where('warehouse_id', $warehouseId)
            ->whereRaw('on_hand_quantity > reserved_quantity')
            ->when(! $includeExpired, fn (Builder $query): Builder => $query->where(
                fn (Builder $usable): Builder => $usable
                    ->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', today()),
            ))
            ->orderByRaw('expires_at is null, expires_at asc')
            ->orderBy('id')
            ->get();
    }

    /**
     * @throws DomainException
     */
    private function lockNamedLot(InventoryOperationLine $line, ProductVariant $variant, int $warehouseId): InventoryLot
    {
        if ($line->inventory_lot_id === null) {
            throw new DomainException(__('admin.inventory.lot.errors.required'));
        }

        $lot = InventoryLot::query()->lockForUpdate()->find($line->inventory_lot_id);

        if (! $lot instanceof InventoryLot) {
            throw new DomainException(__('admin.inventory.lot.errors.required'));
        }

        // A lot belongs to one variant in one warehouse; a line naming someone else's lot would
        // otherwise silently draw stock from the wrong place.
        if ($lot->product_variant_id !== $variant->getKey() || $lot->warehouse_id !== $warehouseId) {
            throw new DomainException(__('admin.inventory.lot.errors.mismatch'));
        }

        return $lot;
    }

    /**
     * @throws DomainException
     */
    private function assertNotExpired(InventoryLot $lot, ?User $actor, bool $allowExpired): void
    {
        if ($lot->expiryState() !== 'expired') {
            return;
        }

        // Both conditions are required: the override is a decision somebody makes, so without an
        // actor to record it against there is nothing to override with.
        if (! $allowExpired || ! $actor instanceof User) {
            throw new DomainException(__('admin.inventory.lot.errors.expired', [
                'lot' => $this->describe($lot),
            ]));
        }

        // Deferred past commit so the alert and audit entry describe an override that actually
        // took effect, rather than one a later failure in the same transaction rolled back.
        DB::afterCommit(function () use ($lot, $actor): void {
            $this->recordOverride($lot, $actor);
        });
    }

    private function recordOverride(InventoryLot $lot, User $actor): void
    {
        $this->inventoryAlertService->raiseExpiredStockReleased($lot, $actor);

        activity()
            ->performedOn($lot)
            ->causedBy($actor)
            ->withChanges([
                'attributes' => [
                    'lot_number' => $lot->lot_number,
                    'expires_at' => $lot->expires_at?->toDateString(),
                ],
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('inventory.lot.expired_stock_released');
    }

    /** A lot number when the batch has one, otherwise its record id, so a message always names it. */
    private function describe(InventoryLot $lot): string
    {
        $lotNumber = $lot->lot_number;

        return $lotNumber === null || $lotNumber === '' ? '#'.$lot->id : $lotNumber;
    }
}
