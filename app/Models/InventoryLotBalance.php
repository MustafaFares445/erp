<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InventoryLotBalance extends Model
{
    #[\Override]
    public function casts(): array
    {
        return [
            'stock_condition' => StockCondition::class,
            'on_hand_base_quantity' => 'decimal:6',
            'reserved_base_quantity' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<InventoryLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function availableBaseQuantity(): string
    {
        if (! $this->stock_condition->allowsReservation()) {
            return '0.000000';
        }

        return bcsub(
            (string) $this->on_hand_base_quantity,
            (string) $this->reserved_base_quantity,
            6,
        );
    }
}
