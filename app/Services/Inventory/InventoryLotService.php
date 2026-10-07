<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryPermission;
use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\Carbon;
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
        if (! $variant->tracksLotsConfigured()) {
            return null;
        }

        if ($variant->tracksExpirationConfigured() && $line->expires_at === null) {
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
        if (! $variant->tracksLotsConfigured()) {
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
        if (! $variant->tracksLotsConfigured()) {
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
     * @param  numeric-string  $baseQuantity
     */
    public function assertReservable(
        InventoryLot $lot,
        int $warehouseId,
        string $baseQuantity,
        ?User $actor,
        bool $allowExpired = false,
    ): InventoryLot {
        $lotKey = $lot->getKey();

        if (! is_int($lotKey)) {
            throw new \LogicException('Inventory lot identifiers must be integers.');
        }

        $locked = $this->lockCanonicalLot($lotKey);
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
        if (! $variant->tracksLotsConfigured()) {
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

    /**
     * Returns the FEFO-preferred lot that can satisfy the requested base quantity.
     * Only expiry-tracked lot variants participate; ordinary lot-tracked inventory keeps
     * its existing explicit allocation behavior.
     *
     * @param  numeric-string  $baseQuantity
     */
    public function preferredFefoLot(
        ProductVariant $variant,
        int $warehouseId,
        string $baseQuantity,
    ): ?InventoryLot {
        if (! $variant->tracksLotsConfigured() || ! $variant->tracksExpirationConfigured()) {
            return null;
        }

        $this->baseQuantity($baseQuantity);
        $variantKey = $variant->getKey();

        if (! is_int($variantKey)) {
            throw new \LogicException('Product variant identifiers must be integers.');
        }

        foreach ($this->availableLots($variantKey, $warehouseId) as $lot) {
            if ($lot->expires_at !== null) {
                return $lot;
            }
        }

        return null;
    }

    /**
     * Enforces FEFO at the reservation boundary. Choosing a later-expiring lot is allowed only
     * with the dedicated permission and a human-readable reason, and is audited after commit.
     *
     * @param  numeric-string  $baseQuantity
     */
    public function assertFefoSelection(
        InventoryLot $selectedLot,
        ProductVariant $variant,
        int $warehouseId,
        string $baseQuantity,
        ?User $actor,
        ?string $overrideReason = null,
    ): InventoryLot {
        if (! $variant->tracksLotsConfigured() || ! $variant->tracksExpirationConfigured()) {
            return $selectedLot;
        }

        if ($selectedLot->product_variant_id !== $variant->getKey()) {
            throw new DomainException(__('admin.inventory.lot.errors.mismatch'));
        }

        if ($selectedLot->expires_at === null) {
            throw new DomainException(__('admin.inventory.product_type.errors.expiry_required'));
        }

        $preferred = $this->preferredFefoLot($variant, $warehouseId, $baseQuantity);

        if (! $preferred instanceof InventoryLot) {
            return $selectedLot;
        }

        /** @var Carbon $preferredExpiry */
        $preferredExpiry = $preferred->expires_at;
        /** @var Carbon $selectedExpiry */
        $selectedExpiry = $selectedLot->expires_at;

        if ($preferredExpiry->toDateString() === $selectedExpiry->toDateString()) {
            return $selectedLot;
        }

        if (! $actor instanceof User || ! $actor->can(InventoryPermission::FefoOverride->value)) {
            throw new DomainException(
                'FEFO requires the earliest valid expiry lot. An authorized override is required to choose a later lot.',
            );
        }

        $reason = is_string($overrideReason) ? mb_trim($overrideReason) : '';

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new DomainException('A FEFO override reason between 1 and 255 characters is required.');
        }

        $selectedLotKey = $selectedLot->getKey();
        $preferredLotKey = $preferred->getKey();

        DB::afterCommit(static function () use ($selectedLot, $selectedLotKey, $preferredLotKey, $actor, $reason): void {
            activity()
                ->performedOn($selectedLot)
                ->causedBy($actor)
                ->withProperties([
                    'preferred_inventory_lot_id' => $preferredLotKey,
                    'selected_inventory_lot_id' => $selectedLotKey,
                    'reason' => $reason,
                    'source_channel' => 'dashboard',
                    'ip_address' => request()->ip(),
                ])
                ->log('inventory.lot.fefo_overridden');
        });

        return $selectedLot;
    }

    public function conditionBalanceForUpdate(
        InventoryLot $lot,
        int $warehouseId,
        StockCondition $condition,
    ): ?InventoryLotBalance {
        return InventoryLotBalance::query()
            ->where('inventory_lot_id', $lot->getKey())
            ->where('warehouse_id', $warehouseId)
            ->where('stock_condition', $condition->value)
            ->lockForUpdate()
            ->first();
    }

    public function saleableBalanceForUpdate(
        InventoryLot $lot,
        int $warehouseId,
    ): ?InventoryLotBalance {
        return $this->conditionBalanceForUpdate(
            $lot,
            $warehouseId,
            StockCondition::Saleable,
        );
    }

    private function resolveOrCreateReceiptIdentity(
        InventoryOperationLine $line,
        ProductVariant $variant,
    ): InventoryLot {
        $normalized = InventoryLot::normalizeLotNumber($line->lot_number);
        $displayNumber = $line->lot_number === null ? null : mb_trim($line->lot_number);

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
        } catch (QueryException $queryException) {
            // Unique-key race recovery: another transaction must create the
            // same canonical lot between our read and insert. The deterministic
            // SQLite harness cannot reproduce that production race reliably.
            $concurrent = $query->lockForUpdate()->first();

            if (! $concurrent instanceof InventoryLot) {
                throw $queryException;
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
        $balance = $this->saleableBalanceForUpdate($lot, $warehouseId);

        return (string) ($balance instanceof InventoryLotBalance ? $balance->on_hand_base_quantity : '0.000000');
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
