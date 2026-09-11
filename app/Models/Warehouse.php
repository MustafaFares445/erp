<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Physical storage facility (ERD §6). Master data every stock/movement row
 * anchors to via `warehouse_id`. Soft-deletable; removal is blocked by the
 * warehouse policy while referenced by stock or movement rows (FR-005).
 */
/**
 * @property int $id
 * @property string $code
 */
#[Fillable(['name', 'code', 'address', 'latitude', 'longitude', 'is_active'])]
final class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /** @return HasMany<InventoryStock, $this> */
    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    /** @return HasMany<WarehouseReplenishmentPolicy, $this> */
    public function replenishmentPolicies(): HasMany
    {
        return $this->hasMany(WarehouseReplenishmentPolicy::class);
    }

    /** @return HasMany<ReplenishmentRequirement, $this> */
    public function replenishmentRequirements(): HasMany
    {
        return $this->hasMany(ReplenishmentRequirement::class);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** @return HasMany<Package, $this> */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function currentOnHand(int $productVariantId): float
    {
        $onHandQuantity = $this->stocks()->where('product_variant_id', $productVariantId)->value('on_hand_quantity');

        return is_numeric($onHandQuantity) ? (float) $onHandQuantity : 0.0;
    }

    public function currentAvailable(int $productVariantId): float
    {
        $availableQuantity = $this->stocks()->where('product_variant_id', $productVariantId)->value('available_quantity');

        return is_numeric($availableQuantity) ? (float) $availableQuantity : 0.0;
    }
}
