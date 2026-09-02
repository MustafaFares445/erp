<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use App\Observers\PackageObserver;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([PackageObserver::class])]
#[Fillable(['name', 'package_type_id', 'warehouse_id', 'is_active'])]
final class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    private bool $isMovingWithRecordedGoods = false;

    #[\Override]
    public function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<PackageType, $this> */
    public function packageType(): BelongsTo
    {
        return $this->belongsTo(PackageType::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** @return HasMany<InventoryOperationLine, $this> */
    public function operationLines(): HasMany
    {
        return $this->hasMany(InventoryOperationLine::class);
    }

    /** @return HasMany<InventoryAdjustmentItem, $this> */
    public function adjustmentItems(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentItem::class);
    }

    public function isReferenced(): bool
    {
        if (! $this->exists) {
            return false;
        }

        if ($this->operationLines()->exists()) {
            return true;
        }

        if ($this->adjustmentItems()->exists()) {
            return true;
        }

        return $this->movements()->exists();
    }

    public static function belongsToWarehouse(?int $packageId, int $warehouseId): bool
    {
        if ($packageId === null) {
            return true;
        }

        return self::query()
            ->whereKey($packageId)
            ->where('warehouse_id', $warehouseId)
            ->exists();
    }

    public function moveWithRecordedGoods(int $warehouseId): void
    {
        $this->isMovingWithRecordedGoods = true;

        try {
            $this->forceFill([
                'warehouse_id' => $warehouseId,
            ])->save();
        } finally {
            $this->isMovingWithRecordedGoods = false;
        }
    }

    public function shouldRejectWarehouseMove(): bool
    {
        return $this->exists
            && $this->isDirty('warehouse_id')
            && $this->isReferenced()
            && ! $this->isMovingWithRecordedGoods;
    }
}
