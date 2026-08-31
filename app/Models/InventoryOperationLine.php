<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AllocationSource;
use Database\Factories\InventoryOperationLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product variant and quantity within an {@see InventoryOperation} (FR-001, data-model.md
 * §3). No independent lifecycle — deleted with its parent (`cascadeOnDelete`).
 */
/**
 * @property int $product_variant_id
 * @property numeric-string $quantity
 * @property int|null $purchase_order_line_id
 * @property numeric-string|null $transaction_quantity
 * @property int|null $transaction_unit_id
 * @property numeric-string|null $conversion_factor_snapshot
 * @property numeric-string|null $base_quantity
 */
#[Fillable([
    'product_variant_id', 'quantity', 'transaction_quantity', 'unit_id', 'transaction_unit_id',
    'conversion_factor_snapshot', 'base_quantity', 'purchase_order_line_id', 'package_id', 'inventory_lot_id',
    'lot_number', 'expires_at', 'serialized_inventory_unit_id', 'is_picked', 'unit_cost', 'allocation_source',
])]
final class InventoryOperationLine extends Model
{
    /** @use HasFactory<InventoryOperationLineFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'transaction_quantity' => 'decimal:6',
            'conversion_factor_snapshot' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'allocation_source' => AllocationSource::class,
            'expires_at' => 'date',
            'is_picked' => 'boolean',
            'unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<InventoryOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class, 'inventory_operation_id');
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

    /** @return BelongsTo<Unit, $this> */
    public function transactionUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'transaction_unit_id');
    }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** @return BelongsTo<InventoryLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function serializedUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class, 'serialized_inventory_unit_id');
    }
}
