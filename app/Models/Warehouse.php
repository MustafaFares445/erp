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
#[Fillable(['name', 'code', 'address', 'is_active'])]
final class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
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
     * @return HasMany<WarehouseLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class);
    }

    /**
     * @return HasMany<InventoryStock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    /**
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * The live on-hand balance for a variant in this warehouse, or 0 if no
     * stock row exists yet. Lets FI-3's `App\Filament\Resources\Adjustments`
     * namespace (not excepted by the write-guard in tests/Unit/ArchTest.php)
     * display the current balance without referencing
     * {@see InventoryStock} directly.
     */
    public function currentOnHand(int $productVariantId): float
    {
        $onHandQuantity = $this->stocks()->where('product_variant_id', $productVariantId)->value('on_hand_quantity');

        return is_numeric($onHandQuantity) ? (float) $onHandQuantity : 0.0;
    }
}
