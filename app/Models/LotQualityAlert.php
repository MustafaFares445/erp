<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "potential lot quality issue" signal raised when open complaints against
 * one lot reach the threshold. An alert only: Inventory alone decides
 * quarantine or disposition.
 *
 * @property int $id
 * @property int $inventory_lot_id
 */
#[Fillable([
    'inventory_lot_id',
    'open_complaints',
    'threshold',
    'raised_at',
    'acknowledged_at',
    'acknowledged_by',
])]
final class LotQualityAlert extends Model
{
    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'open_complaints' => 'integer',
            'threshold' => 'integer',
            'raised_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InventoryLot, $this> */
    public function inventoryLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class);
    }
}
