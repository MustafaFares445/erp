<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use App\Services\Purchasing\PurchaseInboundService;
use Database\Factories\PurchaseInboundAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The warehouse one inbound line will be received into.
 *
 * `purchase_inbound_line_id` is unique (Phase 0 scope: one warehouse per
 * line — see the migration for why splitting a line across warehouses is left
 * to a later phase). Written only by {@see PurchaseInboundService::allocate()}.
 *
 * @property int $id
 * @property int $purchase_inbound_line_id
 * @property int $warehouse_id
 * @property PurchaseInboundLine $purchaseInboundLine
 * @property Warehouse $warehouse
 */
#[Fillable([
    'purchase_inbound_line_id',
    'warehouse_id',
])]
final class PurchaseInboundAllocation extends Model
{
    /** @use HasFactory<PurchaseInboundAllocationFactory> */
    use HasFactory;

    use TracksBlameable;

    /** @return BelongsTo<PurchaseInboundLine, $this> */
    public function purchaseInboundLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseInboundLine::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
