<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Database\Factories\WarehouseLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A location/bin subdivision inside a {@see Warehouse} (ERD §6).
 */
#[Fillable(['warehouse_id', 'name', 'code', 'is_active'])]
final class WarehouseLocation extends Model
{
    /** @use HasFactory<WarehouseLocationFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<SerializedInventoryUnit, $this> */
    public function serializedUnits(): HasMany
    {
        return $this->hasMany(SerializedInventoryUnit::class, 'warehouse_location_id');
    }

    /** @return HasMany<InventoryLot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(InventoryLot::class, 'warehouse_location_id');
    }

    /**
     * Whether an (optional) location id is a real, active location belonging
     * to the given warehouse. A `null` location is always valid — location is
     * an optional refinement of a warehouse-level operation, never required.
     */
    public static function belongsToWarehouse(?int $locationId, int $warehouseId): bool
    {
        if ($locationId === null) {
            return true;
        }

        return self::query()
            ->whereKey($locationId)
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->exists();
    }
}
