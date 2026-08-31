<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MovementType;
use App\Enums\StockCondition;
use App\Observers\InventoryMovementObserver;
use App\Services\Inventory\InventoryPostingService;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable ledger entry recording one stock change (ERD §6).
 *
 * @property int|null $package_id
 *
 * READ-ONLY / IMMUTABLE in the Filament dashboard: the inventory movement
 * policy denies every write ability, and the stock-movement resource
 * registers no create/edit/delete action (FR-015). New canonical rows are
 * written by {@see InventoryPostingService}; the
 * temporary legacy writers are constrained by the migration architecture test.
 */
#[ObservedBy(InventoryMovementObserver::class)]
final class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'quantity' => 'decimal:6',
            'transaction_quantity' => 'decimal:6',
            'conversion_factor_snapshot' => 'decimal:6',
            'base_quantity_delta' => 'decimal:6',
            'stock_condition_from' => StockCondition::class,
            'stock_condition_to' => StockCondition::class,
            'condition_from_on_hand_before' => 'decimal:6',
            'condition_from_on_hand_after' => 'decimal:6',
            'condition_from_reserved_before' => 'decimal:6',
            'condition_from_reserved_after' => 'decimal:6',
            'condition_to_on_hand_before' => 'decimal:6',
            'condition_to_on_hand_after' => 'decimal:6',
            'condition_to_reserved_before' => 'decimal:6',
            'condition_to_reserved_after' => 'decimal:6',
        ];
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

    /** @return BelongsTo<InventoryMovement, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_movement_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function transactionUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'transaction_unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<InventoryReceiptItem, $this> */
    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(InventoryReceiptItem::class, 'inventory_receipt_item_id');
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function serializedUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class, 'serialized_inventory_unit_id');
    }

    /** @return BelongsTo<InventoryLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
