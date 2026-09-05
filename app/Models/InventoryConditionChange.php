<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryConditionChangeType;
use App\Enums\QuarantineDisposition;
use App\Enums\StockCondition;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'document_number',
    'type',
    'status',
    'product_variant_id',
    'warehouse_id',
    'inventory_lot_id',
    'serialized_inventory_unit_id',
    'condition_from',
    'condition_to',
    'base_quantity',
    'disposition',
    'reason_category',
    'reason',
    'inspected_by',
    'inspected_at',
    'posted_by',
    'posted_at',
    'created_by',
    'inventory_movement_id',
    'supplier_return_id',
])]
final class InventoryConditionChange extends Model
{
    use SoftDeletes;

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $change): void {
            $rawStatus = $change->getRawOriginal('status');
            $original = is_string($rawStatus)
                ? InventoryConditionChangeStatus::tryFrom($rawStatus)
                : null;

            if ($original?->isTerminal() !== true) {
                return;
            }

            $allowed = ['updated_at'];

            if (array_diff(array_keys($change->getDirty()), $allowed) !== []) {
                throw new DomainException('Posted and cancelled inventory condition changes are immutable.');
            }
        });

        self::deleting(function (self $change): void {
            if ($change->status !== InventoryConditionChangeStatus::Draft) {
                throw new DomainException('Only a draft inventory condition change may be deleted.');
            }
        });
    }

    #[\Override]
    public function casts(): array
    {
        return [
            'type' => InventoryConditionChangeType::class,
            'status' => InventoryConditionChangeStatus::class,
            'condition_from' => StockCondition::class,
            'condition_to' => StockCondition::class,
            'base_quantity' => 'decimal:6',
            'disposition' => QuarantineDisposition::class,
            'reason_category' => ConditionChangeReason::class,
            'inspected_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
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

    /** @return BelongsTo<User, $this> */
    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<InventoryMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    /** @return BelongsTo<InventoryReturn, $this> */
    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(InventoryReturn::class, 'supplier_return_id');
    }

    public function isDraft(): bool
    {
        return $this->status === InventoryConditionChangeStatus::Draft;
    }
}
