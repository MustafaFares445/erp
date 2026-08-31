<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InventoryCorrectionLineFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'inventory_correction_id',
    'original_inventory_movement_id',
    'original_inventory_operation_line_id',
    'product_variant_id',
    'warehouse_id',
    'transaction_quantity',
    'transaction_unit_id',
    'conversion_factor_snapshot',
    'base_quantity',
    'inventory_lot_id',
    'serialized_inventory_unit_id',
])]
final class InventoryCorrectionLine extends Model
{
    /** @use HasFactory<InventoryCorrectionLineFactory> */
    use HasFactory;

    #[\Override]
    protected static function booted(): void
    {
        self::creating(function (self $line): void {
            $correction = InventoryCorrection::query()->find($line->inventory_correction_id);

            if (! $correction instanceof InventoryCorrection || ! $correction->isDraft()) {
                throw new DomainException('Correction lines can only be created on a draft correction.');
            }
        });

        self::updating(function (self $line): void {
            $correction = InventoryCorrection::query()->find($line->inventory_correction_id);

            if (! $correction instanceof InventoryCorrection || ! $correction->isDraft()) {
                throw new DomainException('Posted and cancelled correction lines are immutable.');
            }
        });

        self::deleting(function (self $line): void {
            $correction = InventoryCorrection::query()->find($line->inventory_correction_id);

            if (! $correction instanceof InventoryCorrection || ! $correction->isDraft()) {
                throw new DomainException('Correction lines can only be removed from a draft correction.');
            }
        });
    }

    #[\Override]
    public function casts(): array
    {
        return [
            'transaction_quantity' => 'decimal:6',
            'conversion_factor_snapshot' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'posted_base_quantity' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<InventoryCorrection, $this> */
    public function correction(): BelongsTo
    {
        return $this->belongsTo(InventoryCorrection::class, 'inventory_correction_id');
    }

    /** @return BelongsTo<InventoryMovement, $this> */
    public function originalMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'original_inventory_movement_id');
    }

    /** @return BelongsTo<InventoryMovement, $this> */
    public function postedMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'posted_inventory_movement_id');
    }

    /** @return BelongsTo<InventoryOperationLine, $this> */
    public function originalOperationLine(): BelongsTo
    {
        return $this->belongsTo(InventoryOperationLine::class, 'original_inventory_operation_line_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
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
