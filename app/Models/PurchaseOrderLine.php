<?php

declare(strict_types=1);

namespace App\Models;

use App\Listeners\AdvancePurchaseOrderOnOperationCompleted;
use App\Services\Purchasing\PurchaseOrderService;
use Database\Factories\PurchaseOrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered variant on a purchase order (data-model.md §3).
 *
 * `quantity_received`, `last_received_unit_cost`, and `line_total` are not
 * fillable (data-model.md §10). The first two are written only by
 * {@see AdvancePurchaseOrderOnOperationCompleted} while holding a row lock, so
 * two concurrent receipts cannot both read a stale figure (R-003); the third is
 * recomputed by {@see PurchaseOrderService} while the order is a draft and
 * frozen thereafter (R-008).
 *
 * No soft delete: a line belongs to its order's lifecycle, and an order that
 * is soft-deleted takes its lines with it.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $product_variant_id
 * @property int $unit_id
 * @property int|null $supplier_product_reference_id
 * @property string|null $supplier_item_number
 * @property string $quantity_ordered
 * @property string $quantity_received
 * @property string $unit_cost
 * @property string|null $last_received_unit_cost
 * @property string $line_total
 * @property PurchaseOrder $purchaseOrder
 * @property ProductVariant $productVariant
 * @property Unit $unit
 */
#[Fillable([
    'purchase_order_id',
    'product_variant_id',
    'unit_id',
    'supplier_product_reference_id',
    'supplier_item_number',
    'quantity_ordered',
    'unit_cost',
    'expected_at',
])]
final class PurchaseOrderLine extends Model
{
    /** @use HasFactory<PurchaseOrderLineFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:3',
            'quantity_received' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'last_received_unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'expected_at' => 'date',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<SupplierProductReference, $this> */
    public function supplierProductReference(): BelongsTo
    {
        return $this->belongsTo(SupplierProductReference::class);
    }

    /**
     * How much of this line is still outstanding.
     *
     * Never negative: over-receipt is refused before it is written (V-08), so a
     * negative result would mean the invariant had already been broken.
     */
    public function outstandingQuantity(): float
    {
        return max(0.0, (float) $this->quantity_ordered - (float) $this->quantity_received);
    }

    public function isFullyReceived(): bool
    {
        return $this->outstandingQuantity() <= 0.0;
    }
}
