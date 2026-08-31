<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\ProductType;
use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Resolves immutable lot identity and validates warehouse/condition eligibility.
 *
 * Quantity is owned exclusively by InventoryLotBalance and is mutated only by
 * InventoryPostingService.
 */
final readonly class InventoryLotService
{
    public function __construct(
        private InventoryAlertService $inventoryAlertService,
    ) {}

    public function receive(
        InventoryOperationLine $line,
        ProductVariant $variant,
        int $warehouseId,
        ?string $baseQuantity = null,
    ): ?InventoryLot {
        $type = $variant->productType();

        if (! $type instanceof ProductType || ! $type->tracksBatches()) {
            return null;
        }

        if ($type->tracksExpiry() && $line->expires_at === null) {
            throw new DomainException(__('admin.inventory.product_type.errors.expiry_required'));
        }

        if ($baseQuantity !== null) {
            $this->baseQuantity($baseQuantity);
        }

        $lot = $this->resolveOrCreateReceiptIdentity($line, $variant);

        $line->forceFill(['inventory_lot_id' => $lot->getKey()])->save();

        return $lot;
    }

    /**
     * Internal transfer receipt preserves the source physical lot identity.
     */
    public function receiveTransfer(
        InventoryOperationLine $line,
        ProductVariant $variant,
        int $warehouseId,
        string $baseQuantity,
    ): ?InventoryLot {
        if ($variant->productType()?->tracksBatches() !== true) {
            return null;
        }

        $this->baseQuantity($baseQuantity);

        $lotId = $line->source_inventory_lot_id ?? $line->inventory_lot_id;

        if (! is_int($lotId)) {
            throw new DomainException(__('admin.inventory.lot.errors.required'));
        }

        return $this->lockCanonicalLot($lotId, $variant);
    }

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

        if (! is_int($line->inventory_lot_id)) {
            throw new DomainException(__('admin.inventory.lot.errors.required'));
        }

        $lot = $this->lockCanonicalLot($line->inventory_lot_id, $variant);
        $quantity = $this->lineBaseQuantity($line);

        $this->assertNotExpired($lot, $actor, $allowExpired);

        if (bccomp($this->saleableOnHandQuantity($lot, $warehouseId), $quantity, 6) < 0) {
            throw new DomainException(__('admin.inventory.lot.errors.insufficient_quantity', [
                'lot' => $this->describe($lot),
            ]));
        }

        return $lot;
    }

    /**
     * @param numeric-string $baseQuantity
     */
    public function assertReservable(
        InventoryLot $lot,
        int $warehouseId,
        string $baseQuantity,
        ?User $actor,
        bool $allowExpired = false,
    ): InventoryLot {
        $locked = $this->lockCanonicalLot((int) $lot->getKey());
        $quantity = $this->baseQuantity($baseQuantity);

        $this->assertNotExpired($locked, $actor, $allowExpired);

        if (bccomp($this->availableQuantity($locked, $warehouseId), $quantity, 6) < 0) {
            throw new DomainException(__('admin.inventory.lot.errors.insufficient_quantity', [
                'lot' => $this->describe($locked),
            ]));
        }

        return $locked;
    }

    public function restore(
        InventoryOperationLine $line,
        ProductVariant $variant,
        ?string $baseQuantity = null,
    ): ?InventoryLot {
        if ($variant->productType()?->tracksBatches() !== true) {
            return null;
        }

        $lotId = $line->source_inventory_lot_id ?? $line->inventory_lot_id;

        if (! is_int($lotId)) {
            return null;
        }

        if ($baseQuantity !== null) {
            $this->baseQuantity($baseQuantity);
        }

        return $this->lockCanonicalLot($lotId, $variant);
    }

    /** @return Collection<int, InventoryLot> */
    public function availableLots(
        int $productVariantId,
        int $warehouseId,
        bool $includeExpired = false,
    ): Collection {
        return InventoryLot::query()
            ->canonical()
            ->where('product_variant_id', $productVariantId)
            ->whereHas('conditionBalances', function (Builder $balance) use ($warehouseId): void {
                $balance->where('warehouse_id', $warehouseId)
                    ->where('stock_condition', StockCondition::Saleable->value)
                    ->whereRaw('on_hand_base_quantity > reserved_base_quantity');
            })
            ->when(! $includeExpired, fn (Builder $query): Builder => $query->where(
                fn (Builder $usable): Builder => $usable
                    ->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', today()),
            ))
            ->orderByRaw('expires_at is null, expires_at asc')
            ->orderBy('id')
            ->get();
    }

    public function saleableBalanceForUpdate(
        InventoryLot $lot,
        int $warehouseId,
    ): ?InventoryLotBalance {
        return InventoryLotBalance::query()
            ->where('inventory_lot_id', $lot->getKey())
            ->where('warehouse_id', $warehouseId)
            ->where('stock_condition', StockCondition::Saleable->value)
            ->lockForUpdate()
            ->first();
    }

    private function resolveOrCreateReceiptIdentity(
        InventoryOperationLine $line,
        ProductVariant $variant,
    ): InventoryLot {
        $normalized = InventoryLot::normalizeLotNumber($line->lot_number);
        $displayNumber = $line->lot_number === null ? null : trim($line->lot_number);

        if ($normalized === null) {
            return InventoryLot::query()->create([
                'product_variant_id' => $variant->getKey(),
                'lot_number' => $displayNumber,
                'normalized_lot_number' => null,
                'expires_at' => $line->expires_at,
                'origin_source_type' => 'inventory_operation',
                'origin_source_id' => $line->inventory_operation_id,
                'origin_source_line_id' => $line->getKey(),
            ]);
        }

        $query = InventoryLot::query()
            ->canonical()
            ->where('product_variant_id', $variant->getKey())
            ->where('normalized_lot_number', $normalized);

        $lot = $query->lockForUpdate()->first();

        if ($lot instanceof InventoryLot) {
            $this->assertExpiryMatches($lot, $line);

            return $lot;
        }

        try {
            $lot = InventoryLot::query()->create([
                'product_variant_id' => $variant->getKey(),
                'lot_number' => $displayNumber,
                'normalized_lot_number' => $normalized,
                'expires_at' => $line->expires_at,
                'origin_source_type' => 'inventory_operation',
                'origin_source_id' => $line->inventory_operation_id,
                'origin_source_line_id' => $line->getKey(),
            ]);
        } catch (QueryException $exception) {
            $concurrent = $query->lockForUpdate()->first();

            if (! $concurrent instanceof InventoryLot) {
                throw $exception;
            }

            $this->assertExpiryMatches($concurrent, $line);

            return $concurrent;
        }

        return $lot;
    }

    private function assertExpiryMatches(
        InventoryLot $lot,
        InventoryOperationLine $line,
    ): void {
        $existing = $lot->expires_at?->toDateString();
        $incoming = $line->expires_at?->toDateString();

        if ($existing !== $incoming) {
            throw new DomainException(
                'The normalized lot number already exists with a different immutable expiry date.',
            );
        }
    }

    private function lockCanonicalLot(
        int $lotId,
        ?ProductVariant $variant = null,
    ): InventoryLot {
        $lot = InventoryLot::query()->lockForUpdate()->find($lotId);

        if (! $lot instanceof InventoryLot) {
            throw new DomainException(__('admin.inventory.lot.errors.required'));
        }

        if (is_int($lot->canonical_inventory_lot_id)) {
            $lot = InventoryLot::query()
                ->lockForUpdate()
                ->findOrFail($lot->canonical_inventory_lot_id);
        }

        if ($variant instanceof ProductVariant && $lot->product_variant_id !== $variant->getKey()) {
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
    private function availableQuantity(InventoryLot $lot, int $warehouseId): string
    {
        $balance = $this->saleableBalanceForUpdate($lot, $warehouseId);

        if (! $balance instanceof InventoryLotBalance) {
            return '0.000000';
        }

        return bcsub(
            (string) $balance->on_hand_base_quantity,
            (string) $balance->reserved_base_quantity,
            6,
        );
    }

    /** @return numeric-string */
    private function saleableOnHandQuantity(InventoryLot $lot, int $warehouseId): string
    {
        return (string) ($this->saleableBalanceForUpdate($lot, $warehouseId)?->on_hand_base_quantity ?? '0.000000');
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
            throw new DomainException(
                'Inventory lot quantities must be exact decimal strings with at most six decimal places.',
            );
        }

        return bcadd($quantity, '0', 6);
    }
}
