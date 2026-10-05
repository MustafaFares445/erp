<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TicketProductContextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivered product line a quality complaint refers to. Created only from
 * verified delivery history, never from customer-supplied lot ids.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $product_variant_id
 * @property int|null $inventory_lot_id
 */
#[Fillable([
    'ticket_id',
    'product_variant_id',
    'inventory_lot_id',
    'original_inventory_operation_line_id',
    'quantity',
    'unit_id',
    'notes',
])]
final class TicketProductContext extends Model
{
    /** @use HasFactory<TicketProductContextFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<InventoryLot, $this> */
    public function inventoryLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class);
    }

    /** @return BelongsTo<InventoryOperationLine, $this> */
    public function originalOperationLine(): BelongsTo
    {
        return $this->belongsTo(InventoryOperationLine::class, 'original_inventory_operation_line_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
