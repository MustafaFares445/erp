<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Purchasing\PurchaseInboundService;
use Database\Factories\PurchaseInboundLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One purchase-order line's membership in its inbound.
 *
 * Created one-for-one with the order's lines by
 * {@see PurchaseInboundService::ensureForAccepted()}. Canonical commercial
 * quantity remains on {@see PurchaseOrderLine}; allocations split that base
 * quantity across one or more warehouses.
 *
 * @property int $id
 * @property int $purchase_inbound_id
 * @property int $purchase_order_line_id
 * @property PurchaseInbound $purchaseInbound
 * @property PurchaseOrderLine $purchaseOrderLine
 * @property \Illuminate\Database\Eloquent\Collection<int, PurchaseInboundAllocation> $allocations
 * @property PurchaseInboundAllocation|null $allocation
 */
#[Fillable([
    'purchase_inbound_id',
    'purchase_order_line_id',
])]
final class PurchaseInboundLine extends Model
{
    private const int QUANTITY_SCALE = 6;

    /** @use HasFactory<PurchaseInboundLineFactory> */
    use HasFactory;

    /** @return BelongsTo<PurchaseInbound, $this> */
    public function purchaseInbound(): BelongsTo
    {
        return $this->belongsTo(PurchaseInbound::class);
    }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /** @return HasMany<PurchaseInboundAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PurchaseInboundAllocation::class);
    }

    /**
     * Legacy one-allocation view retained only for existing Phase-0 callers.
     *
     * New multi-warehouse logic must use {@see self::allocations()}.
     *
     * @deprecated Use allocations().
     *
     * @return HasOne<PurchaseInboundAllocation, $this>
     */
    public function allocation(): HasOne
    {
        return $this->hasOne(PurchaseInboundAllocation::class);
    }

    public function inboundBaseQuantity(): ?string
    {
        $baseQuantity = $this->purchaseOrderLine()->value('base_quantity');

        return $baseQuantity === null
            ? null
            : bcadd('0.000000', (string) $baseQuantity, self::QUANTITY_SCALE);
    }

    public function allocatedBaseQuantity(): string
    {
        $allocated = $this->allocations()->sum('allocated_base_quantity');

        return bcadd('0.000000', (string) $allocated, self::QUANTITY_SCALE);
    }

    public function unallocatedBaseQuantity(): ?string
    {
        $inbound = $this->inboundBaseQuantity();

        if ($inbound === null) {
            return null;
        }

        $remaining = bcsub($inbound, $this->allocatedBaseQuantity(), self::QUANTITY_SCALE);

        return bccomp($remaining, '0.000000', self::QUANTITY_SCALE) === -1
            ? '0.000000'
            : $remaining;
    }
}
