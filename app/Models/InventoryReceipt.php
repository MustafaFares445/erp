<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReceiptStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\InventoryReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $warehouse_id
 * @property Collection<int, InventoryReceiptItem> $items
 */
#[Fillable(['warehouse_id', 'supplier_id', 'supplier_reference', 'notes'])]
final class InventoryReceipt extends Model
{
    /** @use HasFactory<InventoryReceiptFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    protected $attributes = ['status' => 'draft'];

    #[\Override]
    public function casts(): array
    {
        return ['status' => ReceiptStatus::class];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<InventoryReceiptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryReceiptItem::class);
    }

    public function isDraft(): bool
    {
        return $this->status === ReceiptStatus::Draft;
    }
}
