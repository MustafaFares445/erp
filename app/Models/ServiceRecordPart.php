<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ServiceRecordPartFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A spare-parts consumption record (FR-080–088, data-model.md §8).
 * Immutable once written — no `updated_at`, and only the `reversed_at`/
 * `reversed_by`/`reversal_movement_id` columns are ever set after creation,
 * and only once (FR-086), mirroring {@see TicketAssignment}'s append-only
 * guard.
 */
#[Fillable([
    'maintenance_task_id',
    'product_variant_id',
    'warehouse_id',
    'quantity',
    'inventory_movement_id',
    'reversed_at',
    'reversed_by',
    'reversal_movement_id',
    'created_by',
])]
final class ServiceRecordPart extends Model
{
    /** @use HasFactory<ServiceRecordPartFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'reversed_at' => 'datetime',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $part): void {
            $allowedDirty = ['reversed_at', 'reversed_by', 'reversal_movement_id'];

            if (array_diff(array_keys($part->getDirty()), $allowedDirty) !== []) {
                throw new DomainException('Service record part consumption records are immutable except for their reversal fields.');
            }
        });

        self::deleting(function (): never {
            throw new DomainException('Service record part consumption records cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<MaintenanceTask, $this>
     */
    public function maintenanceTask(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * The consumption movement (negative quantity).
     *
     * @return BelongsTo<InventoryMovement, $this>
     */
    public function consumptionMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    /**
     * The compensating movement (positive quantity) — null until reversed.
     *
     * @return BelongsTo<InventoryMovement, $this>
     */
    public function reversalMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'reversal_movement_id');
    }

    /**
     * The actor who recorded the consumption (FR-087).
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The actor who recorded the reversal — null until reversed.
     *
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
