<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'order_line_id', 'product_variant_id', 'destination_warehouse_id',
    'supplier_confirmation_id', 'purchase_order_id', 'purchase_order_line_id',
    'required_base_quantity', 'fulfilled_base_quantity', 'status', 'notes',
])]
final class SalesProcurementRequirement extends Model
{
    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'required_base_quantity' => 'decimal:6',
            'fulfilled_base_quantity' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }

    /** @return BelongsTo<OrderLine, $this> */
    public function orderLine(): BelongsTo { return $this->belongsTo(OrderLine::class); }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo { return $this->belongsTo(ProductVariant::class); }

    /** @return BelongsTo<Warehouse, $this> */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /** @return BelongsTo<SupplierConfirmation, $this> */
    public function supplierConfirmation(): BelongsTo { return $this->belongsTo(SupplierConfirmation::class); }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function purchaseOrderLine(): BelongsTo { return $this->belongsTo(PurchaseOrderLine::class); }

    public function outstandingBaseQuantity(): string
    {
        $remaining = bcsub((string) $this->required_base_quantity, (string) $this->fulfilled_base_quantity, 6);

        return bccomp($remaining, '0.000000', 6) === 1 ? $remaining : '0.000000';
    }

    public function isFulfilled(): bool
    {
        return bccomp($this->outstandingBaseQuantity(), '0.000000', 6) === 0;
    }
}
