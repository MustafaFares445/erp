<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\StockTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Legacy transfer persistence retained temporarily for Phase 10 reconciliation/backfill.
 *
 * Runtime transfer workflows use InventoryOperation(type=InternalTransfer). No service,
 * Filament resource, policy, or observer may mutate this model during the remediation.
 */
/**
 * @property int $id
 * @property int $from_warehouse_id
 * @property int $to_warehouse_id
 * @property Collection<int, StockTransferItem> $items
 */
#[Fillable(['from_warehouse_id', 'to_warehouse_id', 'notes'])]
final class StockTransfer extends Model
{
    /** @use HasFactory<StockTransferFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    /**
     * @return HasMany<StockTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    /**
     * The ledger movements this transfer produced on confirm, linked via the
     * free-form `source_type`/`source_id` reference (not a foreign key)
     * rather than a true relation column (ERD §6). Defined here — not in
     * `App\Filament` — so the read-only cross-module link (FR-015) never
     * needs to reference {@see InventoryMovement} from the Filament layer
     * (arch guard, research D6).
     *
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'source_id')
            ->where('source_type', 'transfer');
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
        return $this->status === TransferStatus::Draft;
    }

    public function isConfirmed(): bool
    {
        return $this->isReceived();
    }

    public function isDispatched(): bool
    {
        return $this->status === TransferStatus::Dispatched;
    }

    public function isReceived(): bool
    {
        return $this->status === TransferStatus::Received;
    }
}
