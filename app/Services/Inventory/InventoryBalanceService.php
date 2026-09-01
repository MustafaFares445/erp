<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryStock;
use App\Models\ProductVariant;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class InventoryBalanceService
{
    private const int QUANTITY_SCALE = 6;

    public function receive(ProductVariant|int $variant, int $warehouseId, float $quantity): InventoryStock
    {
        $this->requirePositive($quantity);

        return DB::transaction(function () use ($variant, $warehouseId, $quantity): InventoryStock {
            $stock = $this->stockForUpdate($this->variantId($variant), $warehouseId, true);

            return $this->persist(
                $stock,
                $this->quantity($stock->on_hand_quantity) + $quantity,
                $this->quantity($stock->reserved_quantity),
                $this->quantity($stock->damaged_quantity),
            );
        }, attempts: 5);
    }

    public function transferOut(ProductVariant|int $variant, int $warehouseId, float $quantity): InventoryStock
    {
        $this->requirePositive($quantity);

        return DB::transaction(function () use ($variant, $warehouseId, $quantity): InventoryStock {
            $stock = $this->stockForUpdate($this->variantId($variant), $warehouseId);

            if ($this->quantity($stock->available_quantity) < $quantity) {
                throw new DomainException(__('admin.inventory.balance.errors.insufficient_available'));
            }

            return $this->persist(
                $stock,
                $this->quantity($stock->on_hand_quantity) - $quantity,
                $this->quantity($stock->reserved_quantity),
                $this->quantity($stock->damaged_quantity),
            );
        }, attempts: 5);
    }

    public function transferIn(ProductVariant|int $variant, int $warehouseId, float $quantity): InventoryStock
    {
        return $this->receive($variant, $warehouseId, $quantity);
    }

    public function adjustTo(ProductVariant|int $variant, int $warehouseId, float $newOnHand): InventoryStock
    {
        if ($newOnHand < 0) {
            throw new DomainException(__('admin.inventory.balance.errors.invalid_quantity'));
        }

        return DB::transaction(function () use ($variant, $warehouseId, $newOnHand): InventoryStock {
            $stock = $this->stockForUpdate($this->variantId($variant), $warehouseId, true);

            return $this->persist(
                $stock,
                $newOnHand,
                $this->quantity($stock->reserved_quantity),
                $this->quantity($stock->damaged_quantity),
            );
        }, attempts: 5);
    }

    public function reserve(InventoryStock $stock, float $quantity): InventoryStock
    {
        $this->requirePositive($quantity);

        return DB::transaction(function () use ($stock, $quantity): InventoryStock {
            $locked = $this->stockByIdForUpdate($stock);

            return $this->persist(
                $locked,
                $this->quantity($locked->on_hand_quantity),
                $this->quantity($locked->reserved_quantity) + $quantity,
                $this->quantity($locked->damaged_quantity),
            );
        }, attempts: 5);
    }

    public function releaseReservation(InventoryStock $stock, float $quantity): InventoryStock
    {
        $this->requirePositive($quantity);

        return DB::transaction(function () use ($stock, $quantity): InventoryStock {
            $locked = $this->stockByIdForUpdate($stock);
            $reserved = $this->quantity($locked->reserved_quantity);

            if ($reserved < $quantity) {
                throw new DomainException(__('admin.inventory.balance.errors.insufficient_reserved'));
            }

            return $this->persist(
                $locked,
                $this->quantity($locked->on_hand_quantity),
                $reserved - $quantity,
                $this->quantity($locked->damaged_quantity),
            );
        }, attempts: 5);
    }

    public function damage(InventoryStock $stock, float $quantity): InventoryStock
    {
        $this->requirePositive($quantity);

        return DB::transaction(function () use ($stock, $quantity): InventoryStock {
            $locked = $this->stockByIdForUpdate($stock);

            if ($this->quantity($locked->available_quantity) < $quantity) {
                throw new DomainException(__('admin.inventory.balance.errors.insufficient_available'));
            }

            return $this->persist(
                $locked,
                $this->quantity($locked->on_hand_quantity),
                $this->quantity($locked->reserved_quantity),
                $this->quantity($locked->damaged_quantity) + $quantity,
            );
        }, attempts: 5);
    }

    public function recoverDamage(InventoryStock $stock, float $quantity): InventoryStock
    {
        $this->requirePositive($quantity);

        return DB::transaction(function () use ($stock, $quantity): InventoryStock {
            $locked = $this->stockByIdForUpdate($stock);
            $damaged = $this->quantity($locked->damaged_quantity);

            if ($damaged < $quantity) {
                throw new DomainException(__('admin.inventory.balance.errors.insufficient_damaged'));
            }

            return $this->persist(
                $locked,
                $this->quantity($locked->on_hand_quantity),
                $this->quantity($locked->reserved_quantity),
                $damaged - $quantity,
            );
        }, attempts: 5);
    }

    public function disposeDamage(InventoryStock $stock, float $quantity): InventoryStock
    {
        $this->requirePositive($quantity);

        return DB::transaction(function () use ($stock, $quantity): InventoryStock {
            $locked = $this->stockByIdForUpdate($stock);
            $damaged = $this->quantity($locked->damaged_quantity);

            if ($damaged < $quantity) {
                throw new DomainException(__('admin.inventory.balance.errors.insufficient_damaged'));
            }

            return $this->persist(
                $locked,
                $this->quantity($locked->on_hand_quantity) - $quantity,
                $this->quantity($locked->reserved_quantity),
                $damaged - $quantity,
            );
        }, attempts: 5);
    }

    /**
     * Finds and locks a balance row for the canonical posting service.
     *
     * @internal Direct workflow callers remain temporarily supported only while
     * the approved remediation migrates them to InventoryPostingService.
     */
    public function stockForUpdate(int $variantId, int $warehouseId, bool $create = false): InventoryStock
    {
        $stock = InventoryStock::query()
            ->where('product_variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if ($stock instanceof InventoryStock) {
            return $stock;
        }

        if (! $create) {
            throw new DomainException(__('admin.inventory.balance.errors.missing_stock'));
        }

        try {
            InventoryStock::query()->forceCreate([
                'product_variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'on_hand_quantity' => 0,
                'reserved_quantity' => 0,
                'damaged_quantity' => 0,
                'available_quantity' => 0,
            ]);
        } catch (QueryException $queryException) {
            $concurrentlyCreated = InventoryStock::query()
                ->where('product_variant_id', $variantId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($concurrentlyCreated instanceof InventoryStock) {
                return $concurrentlyCreated;
            }

            throw $queryException;
        }

        return InventoryStock::query()
            ->where('product_variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function stockByIdForUpdate(InventoryStock $stock): InventoryStock
    {
        return InventoryStock::query()->lockForUpdate()->findOrFail($this->stockId($stock));
    }

    /**
     * Applies exact normalized base-quantity deltas to an already locked
     * balance. InventoryPostingService is the sole non-legacy caller.
     *
     * @internal
     */
    public function applyLockedDeltas(
        InventoryStock $stock,
        string $onHandDelta,
        string $reservedDelta,
        string $damagedDelta,
    ): InventoryStock {
        $onHand = bcadd($this->decimalQuantity($stock->on_hand_quantity), $this->decimalQuantity($onHandDelta), self::QUANTITY_SCALE);
        $reserved = bcadd($this->decimalQuantity($stock->reserved_quantity), $this->decimalQuantity($reservedDelta), self::QUANTITY_SCALE);
        $damaged = bcadd($this->decimalQuantity($stock->damaged_quantity), $this->decimalQuantity($damagedDelta), self::QUANTITY_SCALE);

        if (
            bccomp($onHand, '0', self::QUANTITY_SCALE) < 0
            || bccomp($reserved, '0', self::QUANTITY_SCALE) < 0
            || bccomp($damaged, '0', self::QUANTITY_SCALE) < 0
            || bccomp(bcadd($reserved, $damaged, self::QUANTITY_SCALE), $onHand, self::QUANTITY_SCALE) > 0
        ) {
            throw new DomainException(__('admin.inventory.balance.errors.invalid_balance'));
        }

        $stock->forceFill([
            'on_hand_quantity' => $onHand,
            'reserved_quantity' => $reserved,
            'damaged_quantity' => $damaged,
            'available_quantity' => bcsub(bcsub($onHand, $reserved, self::QUANTITY_SCALE), $damaged, self::QUANTITY_SCALE),
        ])->save();

        return $stock->refresh();
    }

    private function persist(
        InventoryStock $stock,
        float $onHand,
        float $reserved,
        float $damaged,
    ): InventoryStock {
        $onHand = round($onHand, self::QUANTITY_SCALE);
        $reserved = round($reserved, self::QUANTITY_SCALE);
        $damaged = round($damaged, self::QUANTITY_SCALE);

        if ($onHand < 0 || $reserved < 0 || $damaged < 0 || $reserved + $damaged > $onHand) {
            throw new DomainException(__('admin.inventory.balance.errors.invalid_balance'));
        }

        $stock->forceFill([
            'on_hand_quantity' => $onHand,
            'reserved_quantity' => $reserved,
            'damaged_quantity' => $damaged,
            'available_quantity' => round($onHand - $reserved - $damaged, self::QUANTITY_SCALE),
        ])->save();

        return $stock->refresh();
    }

    private function requirePositive(float $quantity): void
    {
        if ($quantity <= 0) {
            throw new DomainException(__('admin.inventory.balance.errors.invalid_quantity'));
        }
    }

    private function quantity(mixed $quantity): float
    {
        if (is_int($quantity) || is_float($quantity)) {
            return (float) $quantity;
        }

        if (is_string($quantity) && is_numeric($quantity)) {
            return (float) $quantity;
        }

        throw new LogicException('Inventory quantities must be numeric.');
    }

    /** @return numeric-string */
    private function decimalQuantity(mixed $quantity): string
    {
        if (! is_string($quantity) || ! is_numeric($quantity) || preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d{1,6})?$/D', $quantity) !== 1) {
            throw new LogicException('Inventory quantities must be exact decimal strings with at most six places.');
        }

        return bcadd($quantity, '0', self::QUANTITY_SCALE);
    }

    private function stockId(InventoryStock $stock): int
    {
        $key = $stock->getKey();

        if (! is_int($key)) {
            throw new LogicException('Inventory stock must use an integer identifier.');
        }

        return $key;
    }

    private function variantId(ProductVariant|int $variant): int
    {
        if (is_int($variant)) {
            return $variant;
        }

        $key = $variant->getKey();

        if (! is_int($key)) {
            throw new LogicException('Product variants must use integer identifiers.');
        }

        return $key;
    }
}
