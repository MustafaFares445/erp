<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryReturnDisposition;
use App\Enums\InventoryReturnStatus;
use App\Enums\StockCondition;
use Database\Factories\InventoryReturnLineFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'inventory_return_id',
    'product_variant_id',
    'transaction_quantity',
    'transaction_unit_id',
    'conversion_factor_snapshot',
    'base_quantity',
    'source_condition',
    'disposition',
    'inventory_lot_id',
    'serialized_inventory_unit_id',
    'original_inventory_operation_line_id',
    'original_inventory_movement_id',
    'inspection_notes',
])]
final class InventoryReturnLine extends Model
{
    /** @use HasFactory<InventoryReturnLineFactory> */
    use HasFactory;

    #[\Override]
    protected static function booted(): void
    {
        self::creating(function (self $line): void {
            $return = InventoryReturn::query()->find($line->inventory_return_id);

            if (! $return instanceof InventoryReturn || ! $return->isDraft()) {
                throw new DomainException('Return lines can only be created on a draft return.');
            }
        });

        self::updating(function (self $line): void {
            $return = InventoryReturn::query()->find($line->inventory_return_id);

            if (! $return instanceof InventoryReturn) {
                throw new DomainException('A return line must belong to an inventory return.');
            }

            if ($return->status->isTerminal()) {
                throw new DomainException('Lines of posted or cancelled inventory returns are immutable.');
            }

            if ($return->status === InventoryReturnStatus::Ready) {
                $allowed = ['posted_base_quantity', 'posted_inventory_movement_id', 'updated_at'];
                $forbidden = array_diff(array_keys($line->getDirty()), $allowed);

                if ($forbidden !== []) {
                    throw new DomainException(
                        'A ready return line is frozen; only canonical posting evidence may be attached.',
                    );
                }
            }
        });

        self::deleting(function (self $line): void {
            $return = InventoryReturn::query()->find($line->inventory_return_id);

            if (! $return instanceof InventoryReturn || ! $return->isDraft()) {
                throw new DomainException('Return lines can only be removed while the return is a draft.');
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
            'source_condition' => StockCondition::class,
            'disposition' => InventoryReturnDisposition::class,
            'inspected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InventoryReturn, $this> */
    public function inventoryReturn(): BelongsTo
    {
        return $this->belongsTo(InventoryReturn::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function transactionUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'transaction_unit_id');
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

    /** @return BelongsTo<InventoryOperationLine, $this> */
    public function originalOperationLine(): BelongsTo
    {
        return $this->belongsTo(InventoryOperationLine::class, 'original_inventory_operation_line_id');
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

    /** @return BelongsTo<User, $this> */
    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
