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
 * Resolves lot identity and validates lot eligibility.
 *
 * It deliberately does not mutate lot quantities. Phase 6 makes
 * InventoryPostingService the only writer of materialized stock and lot balances.
 */
final readonly class InventoryLotService
{
    public function __construct(
        private InventoryAlertService $inventoryAlertService,
    ) {}

    /**
     * Resolves or creates the stable destination lot identity for an inbound line.
     * Quantity remains zero until InventoryPostingService posts the receipt.
     */
    public function receive(
        InventoryOperationLine $line,
        ProductVariant $variant,
        int $warehouseId,
        ?string $baseQuantity = null,
    ): ?InventoryLot {
        return $this->resolveInboundIdentity($line, $variant, $warehouseId, true);
    }

    /**
     * Resolves or creates the destination lot identity for a transfer receipt.
     * The source allocation on the operation line is retained.
     */
    public function receiveTransfer(
        InventoryOperationLine $line,
        ProductVariant $variant,
        int $warehouseId,
        string $baseQuantity,
    ): ?InventoryLot {
        return $this->resolveInboundIdentity($line, $variant, $warehouseId, false);
    }

    private function resolveInboundIdentity(
        InventoryOperationLine $line,
        ProductVariant $variant,
        int $warehouseId,
        bool $assignLineLot,
    ): ?InventoryLot {
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

        if (! $lot instanceof InventoryLot) {
            $lot = InventoryLot::query()->create([
                'product_variant_id' => $variant->getKey(),
                'warehouse_id' => $warehouseId,
                'lot_number' => $line->lot_number,
                'expires_at' => $expiresAt,
                'on_hand_quantity' => '0.000000',
                'reserved_quantity' => '0.000000',
            ]);
        }

        if ($assignLineLot) {
            $line->forceFill(['inventory_lot_id' => $lot->getKey()])->save();
        }

        return $lot;
    }

    /**
     * Returns the allocated outbound lot after validating identity, expiry and supply.
     * No quantity is changed here.
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
        $quantity = $this->lineBaseQuantity($line);

        $this->assertNotExpired($lot, $actor, $allowExpired);

        if (bccomp((string) $lot->on_hand_quantity, $quantity, 6) < 0) {
            throw new DomainException(__('admin.inventory.lot.errors.insufficient_quantity', [
                'lot' => $this->describe($lot),
            ]));
        }

        return $lot;
    }

    /**
     * Validates a reservation allocation without changing its lot balance.
     *
     * @param numeric-string $baseQuantity
     */
    public function assertReservable(
        InventoryLot $lot,
        string $baseQuantity,
        ?User $actor,
        bool $allowExpired = false,
    ): InventoryLot {
        $locked = InventoryLot::query()->lockForUpdate()->findOrFail($lot->getKey());
        $quantity = $this->baseQuantity($baseQuantity);

        $this->assertNotExpired($locked, $actor, $allowExpired);

        if (bccomp($this->availableQuantity($locked), $quantity, 6) < 0) {
            throw new DomainException(__('admin.inventory.lot.errors.insufficient_quantity', [
                'lot' => $this->describe($locked),
            ]));
        }

        return $locked;
    }

    /**
     * Resolves the original source lot for a compensating transfer restoration.
     * No quantity is changed here.
     */
    public function restore(
        InventoryOperationLine $line,
        ProductVariant $variant,
        ?string $baseQuantity = null,
    ): ?InventoryLot {
        if ($variant->productType()?->tracksBatches() !== true || $line->inventory_lot_id === null) {
            return null;
        }

        $lot = InventoryLot::query()->lockForUpdate()->find($line->inventory_lot_id);

        if (! $lot instanceof InventoryLot || $lot->product_variant_id !== $variant->getKey()) {
            return null;
        }

        if ($baseQuantity !== null) {
            $this->baseQuantity($baseQuantity);
        }

        return $lot;
    }

    /** @return Collection<int, InventoryLot> */
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

    private function lockNamedLot(
        InventoryOperationLine $line,
        ProductVariant $variant,
        int $warehouseId,
    ): InventoryLot {
        if ($line->inventory_lot_id === null) {
            throw new DomainException(__('admin.inventory.lot.errors.required'));
        }

        $lot = InventoryLot::query()->lockForUpdate()->find($line->inventory_lot_id);

        if (! $lot instanceof InventoryLot) {
            throw new DomainException(__('admin.inventory.lot.errors.required'));
        }

        if ($lot->product_variant_id !== $variant->getKey() || $lot->warehouse_id !== $warehouseId) {
            throw new DomainException(__('admin.inventory.lot.errors.mismatch'));
        }

        return $lot;
    }

    private function assertNotExpired(InventoryLot $lot, ?User $actor, bool $allowExpired): void
    {
        if ($lot->expiryState() !== 'expired') {
            return;
        }

        if (! $allowExpired || ! $actor instanceof User) {
            throw new DomainException(__('admin.inventory.lot.errors.expired', [
                'lot' => $this->describe($lot),
            ]));
        }

        DB::afterCommit(function () use ($lot, $actor): void {
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
        });
    }

    /** @return numeric-string */
    private function availableQuantity(InventoryLot $lot): string
    {
        return bcsub((string) $lot->on_hand_quantity, (string) $lot->reserved_quantity, 6);
    }

    private function describe(InventoryLot $lot): string
    {
        $lotNumber = $lot->lot_number;

        return $lotNumber === null || $lotNumber === '' ? '#'.$lot->id : $lotNumber;
    }

    /** @return numeric-string */
    private function lineBaseQuantity(InventoryOperationLine $line): string
    {
        return $this->baseQuantity((string) ($line->base_quantity ?? $line->quantity));
    }

    /** @return numeric-string */
    private function baseQuantity(string $quantity): string
    {
        if (! is_numeric($quantity) || preg_match('/^\d+(?:\.\d{1,6})?$/D', $quantity) !== 1) {
            throw new DomainException('Inventory lot quantities must be exact decimal strings with at most six decimal places.');
        }

        return bcadd($quantity, '0', 6);
    }
}
