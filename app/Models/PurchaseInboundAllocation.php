<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\Concerns\TracksBlameable;
use App\Services\Purchasing\PurchaseInboundService;
use Database\Factories\PurchaseInboundAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One warehouse quantity split for a purchase inbound line.
 *
 * Multiple allocations may belong to the same inbound line, but one warehouse
 * may appear at most once per line. New allocation writes are owned by the
 * purchasing allocation workflow; the legacy {@see PurchaseInboundService}
 * remains compatible until Phase 4.3 replaces its whole-line write path.
 *
 * @property int $id
 * @property int $purchase_inbound_line_id
 * @property int $warehouse_id
 * @property numeric-string|null $allocated_base_quantity
 * @property PurchaseInboundLine $purchaseInboundLine
 * @property Warehouse $warehouse
 * @property Collection<int, InventoryOperationLine> $inventoryOperationLines
 */
#[Fillable([
    'purchase_inbound_line_id',
    'warehouse_id',
    'allocated_base_quantity',
])]
final class PurchaseInboundAllocation extends Model
{
    private const int QUANTITY_SCALE = 6;

    /** @use HasFactory<PurchaseInboundAllocationFactory> */
    use HasFactory;

    use TracksBlameable;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'allocated_base_quantity' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<PurchaseInboundLine, $this> */
    public function purchaseInboundLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseInboundLine::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<InventoryOperationLine, $this> */
    public function inventoryOperationLines(): HasMany
    {
        return $this->hasMany(InventoryOperationLine::class, 'purchase_inbound_allocation_id');
    }

    public function receivedBaseQuantity(): string
    {
        $received = $this->inventoryOperationLines()
            ->whereNotNull('base_quantity')
            ->whereHas('operation', static fn ($query) => $query
                ->where('operation_type', OperationType::Receipt->value)
                ->where('stage', OperationStage::Done->value))
            ->sum('base_quantity');

        return bcadd('0.000000', (string) $received, self::QUANTITY_SCALE);
    }

    public function remainingBaseQuantity(): ?string
    {
        if ($this->allocated_base_quantity === null) {
            return null;
        }

        $remaining = bcsub(
            $this->allocated_base_quantity,
            $this->receivedBaseQuantity(),
            self::QUANTITY_SCALE,
        );

        return bccomp($remaining, '0.000000', self::QUANTITY_SCALE) === -1
            ? '0.000000'
            : $remaining;
    }
}
