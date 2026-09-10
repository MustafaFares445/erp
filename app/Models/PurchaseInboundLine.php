<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Purchasing\PurchaseInboundService;
use Database\Factories\PurchaseInboundLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One purchase-order line's membership in its inbound.
 *
 * Created one-for-one with the order's lines by
 * {@see PurchaseInboundService::ensureForAccepted()}. Quantity and receipt
 * reconciliation stay on {@see PurchaseOrderLine} itself; this row exists only
 * to hang a warehouse allocation off a line without coupling the order to one.
 *
 * @property int $id
 * @property int $purchase_inbound_id
 * @property int $purchase_order_line_id
 * @property PurchaseInbound $purchaseInbound
 * @property PurchaseOrderLine $purchaseOrderLine
 * @property PurchaseInboundAllocation|null $allocation
 */
#[Fillable([
    'purchase_inbound_id',
    'purchase_order_line_id',
])]
final class PurchaseInboundLine extends Model
{
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

    /** @return HasOne<PurchaseInboundAllocation, $this> */
    public function allocation(): HasOne
    {
        return $this->hasOne(PurchaseInboundAllocation::class);
    }
}
