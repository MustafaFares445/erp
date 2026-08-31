<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InventoryReservationAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'inventory_reservation_id',
    'inventory_lot_id',
    'serialized_inventory_unit_id',
    'base_quantity',
])]
final class InventoryReservationAllocation extends Model
{
    /** @use HasFactory<InventoryReservationAllocationFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return ['base_quantity' => 'decimal:6'];
    }

    /** @return BelongsTo<InventoryReservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'inventory_reservation_id');
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
