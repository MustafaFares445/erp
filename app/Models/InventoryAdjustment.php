<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdjustmentStatus;
use App\Models\Concerns\TracksBlameable;
use App\Policies\InventoryAdjustmentPolicy;
use App\Services\Inventory\InventoryAdjustmentService;
use Database\Factories\InventoryAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document correcting stock in one warehouse (ERD §6, FI-3).
 *
 * Editable only while {@see AdjustmentStatus::Draft}; immutable once
 * {@see AdjustmentStatus::Confirmed} (enforced by
 * {@see InventoryAdjustmentPolicy}, not here). Soft-deletable
 * (recoverable), never hard-deleted. `adjustment_number` and `status` are
 * service-owned — assigned only by
 * {@see InventoryAdjustmentService::confirm()} —
 * and therefore not fillable.
 */
#[Fillable(['warehouse_id', 'reason'])]
final class InventoryAdjustment extends Model
{
    /** @use HasFactory<InventoryAdjustmentFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => AdjustmentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<InventoryAdjustmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentItem::class);
    }

    /**
     * The confirmed adjustment this draft or confirmed adjustment corrects.
     *
     * @return BelongsTo<InventoryAdjustment, $this>
     */
    public function correctsAdjustment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_adjustment_id');
    }

    /**
     * Later correcting adjustments linked to this immutable adjustment.
     *
     * @return HasMany<InventoryAdjustment, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'corrects_adjustment_id');
    }

    /**
     * The ledger movements this adjustment produced on confirm, linked via
     * the free-form `source_type`/`source_id` reference (not a foreign
     * key) rather than a true relation column (ERD §6). Defined here — not
     * in `App\Filament` — so the read-only cross-module link (FR-014) never
     * needs to reference {@see InventoryMovement} from the Filament layer
     * (arch guard, research R4).
     *
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'source_id')
            ->where('source_type', 'adjustment');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === AdjustmentStatus::Draft;
    }

    public function isConfirmed(): bool
    {
        return $this->status === AdjustmentStatus::Confirmed;
    }
}
