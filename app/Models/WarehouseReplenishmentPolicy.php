<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Closure;
use Database\Factories\WarehouseReplenishmentPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * How much of one product variant a warehouse wants to keep in stock,
 * independent of whether any stock currently exists there (Phase 0
 * remediation — replaces `InventoryStock.reorder_level`, which could not be
 * set until a stock row existed).
 *
 * `is_active` lets a policy be retired without losing its history; an
 * inactive policy is not read by low-stock evaluation.
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $product_variant_id
 * @property string $min_quantity
 * @property string $max_quantity
 * @property bool $is_active
 * @property Warehouse $warehouse
 * @property ProductVariant $productVariant
 */
#[Fillable([
    'warehouse_id',
    'product_variant_id',
    'min_quantity',
    'max_quantity',
    'is_active',
])]
final class WarehouseReplenishmentPolicy extends Model
{
    /** @use HasFactory<WarehouseReplenishmentPolicyFactory> */
    use HasFactory;

    use TracksBlameable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'min_quantity' => 'decimal:6',
            'max_quantity' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * Whether available stock has fallen to or below this policy's minimum.
     *
     * Lives here rather than on {@see InventoryStock} because the comparison
     * is a policy concern, not a fact about the stock aggregate itself — the
     * same reasoning that moved the threshold off that model in the first
     * place.
     */
    public function isBreachedBy(InventoryStock $stock): bool
    {
        return $this->is_active && (float) $stock->available_quantity <= (float) $this->min_quantity;
    }

    /**
     * A correlated `whereExists()` subquery closure for "an
     * `inventory_stocks` row has an active policy whose minimum it has
     * fallen to or below" — the query-level equivalent of
     * {@see self::isBreachedBy()}, for callers filtering many stock rows at
     * once instead of evaluating one at a time.
     */
    public static function breachedSubquery(): Closure
    {
        return function (QueryBuilder $policies): void {
            $policies->from('warehouse_replenishment_policies')
                ->whereColumn('warehouse_replenishment_policies.warehouse_id', 'inventory_stocks.warehouse_id')
                ->whereColumn('warehouse_replenishment_policies.product_variant_id', 'inventory_stocks.product_variant_id')
                ->where('warehouse_replenishment_policies.is_active', true)
                ->whereColumn('inventory_stocks.available_quantity', '<=', 'warehouse_replenishment_policies.min_quantity');
        };
    }
}
