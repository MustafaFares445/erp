<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CountScope;
use App\Enums\InventoryCountStatus;
use App\Services\Inventory\InventoryCountService;
use Database\Factories\InventoryCountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical stock count document (GAP-MW-06, IN-06).
 *
 * Generates one {@see InventoryCountLine} per grain in scope on
 * {@see InventoryCountService::open()}, then produces at most one
 * {@see InventoryAdjustment} on {@see InventoryCountService::confirm()} — the
 * count never posts stock itself. `count_number`, `status`, `is_partial`,
 * `counted_by`, `confirmed_by`, `confirmed_at`, `inventory_adjustment_id`,
 * `opened_at`, and `closed_at` are service-owned and therefore not fillable.
 */
#[Fillable(['warehouse_id', 'scope_type', 'product_category_id', 'inventory_lot_id', 'conditions', 'materiality_threshold_minor'])]
final class InventoryCount extends Model
{
    /** @use HasFactory<InventoryCountFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => InventoryCountStatus::class,
            'scope_type' => CountScope::class,
            'conditions' => 'array',
            'is_partial' => 'boolean',
            'confirmed_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    /** @return BelongsTo<InventoryLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    /** @return HasMany<InventoryCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryCountLine::class);
    }

    /** @return BelongsTo<InventoryAdjustment, $this> */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustment::class, 'inventory_adjustment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === InventoryCountStatus::Draft;
    }

    public function isCounting(): bool
    {
        return $this->status === InventoryCountStatus::Counting;
    }

    public function isPendingReview(): bool
    {
        return $this->status === InventoryCountStatus::PendingReview;
    }

    public function isConfirmed(): bool
    {
        return $this->status === InventoryCountStatus::Confirmed;
    }
}
