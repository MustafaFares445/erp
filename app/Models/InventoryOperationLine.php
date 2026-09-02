<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationSource;
use App\Enums\TransferDiscrepancyDisposition;
use Database\Factories\InventoryOperationLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_variant_id', 'quantity', 'transaction_quantity', 'unit_id', 'transaction_unit_id',
    'conversion_factor_snapshot', 'base_quantity', 'purchase_order_line_id', 'order_line_id',
    'package_id', 'inventory_lot_id', 'lot_number', 'expires_at', 'serialized_inventory_unit_id',
    'is_picked', 'unit_cost', 'allocation_source',
])]
final class InventoryOperationLine extends Model
{
    /** @use HasFactory<InventoryOperationLineFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'transaction_quantity' => 'decimal:6',
            'conversion_factor_snapshot' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'dispatched_base_quantity' => 'decimal:6',
            'received_base_quantity' => 'decimal:6',
            'discrepancy_disposition' => TransferDiscrepancyDisposition::class,
            'allocation_source' => AllocationSource::class,
            'expires_at' => 'date',
            'is_picked' => 'boolean',
            'unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<InventoryOperation, $this> */
    public function operation(): BelongsTo { return $this->belongsTo(InventoryOperation::class, 'inventory_operation_id'); }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo { return $this->belongsTo(ProductVariant::class); }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class); }

    /** @return BelongsTo<Unit, $this> */
    public function transactionUnit(): BelongsTo { return $this->belongsTo(Unit::class, 'transaction_unit_id'); }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function purchaseOrderLine(): BelongsTo { return $this->belongsTo(PurchaseOrderLine::class); }

    /** @return BelongsTo<OrderLine, $this> */
    public function orderLine(): BelongsTo { return $this->belongsTo(OrderLine::class); }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo { return $this->belongsTo(Package::class); }

    /** @return BelongsTo<InventoryLot, $this> */
    public function lot(): BelongsTo { return $this->belongsTo(InventoryLot::class, 'inventory_lot_id'); }

    /** @return BelongsTo<InventoryLot, $this> */
    public function sourceLot(): BelongsTo { return $this->belongsTo(InventoryLot::class, 'source_inventory_lot_id'); }

    /** @return BelongsTo<InventoryLot, $this> */
    public function destinationLot(): BelongsTo { return $this->belongsTo(InventoryLot::class, 'destination_inventory_lot_id'); }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function serializedUnit(): BelongsTo { return $this->belongsTo(SerializedInventoryUnit::class, 'serialized_inventory_unit_id'); }
}
