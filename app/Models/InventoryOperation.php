<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\Concerns\TracksBlameable;
use App\Services\Inventory\InventoryOperationService;
use Database\Factories\InventoryOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One warehouse movement document — a Receipt, Delivery or Internal Transfer sharing a single
 * Draft→Waiting→Ready→(InTransit)→Done→Canceled lifecycle (FR-001, FR-002, data-model.md §2).
 *
 * `stage`, `operation_number`, `dispatched_at`, `completed_at` and `canceled_at` are
 * service-owned — assigned only by {@see InventoryOperationService} — and therefore not
 * fillable, mirroring how {@see StockTransfer} treats `status`/`transfer_number`. Immutable and
 * undeletable once `stage` is `Done` or `Canceled` (V-04, enforced by the policy, not here).
 */
/**
 * @property int $id
 * @property int $destination_warehouse_id
 * @property Collection<int, InventoryOperationLine> $lines
 */
#[Fillable([
    'operation_type', 'source_warehouse_id', 'destination_warehouse_id', 'supplier_id',
    'source_document_type', 'source_document_id', 'supplier_reference', 'scheduled_at',
    'responsible_id', 'notes',
])]
final class InventoryOperation extends Model
{
    /** @use HasFactory<InventoryOperationFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /**
     * Mirrors the `stage` column's DB-level default, matching
     * {@see StockTransfer}'s reasoning: observers and Filament forms may read
     * `$operation->stage` in-memory before the row is refetched.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stage' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'operation_type' => OperationType::class,
            'stage' => OperationStage::class,
            'scheduled_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<InventoryOperationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryOperationLine::class);
    }

    /**
     * The originating commercial document — a purchase order for a receipt, a sales delivery
     * note for a delivery (FR-012).
     *
     * @return MorphTo<Model, $this>
     */
    public function sourceDocument(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The ledger movements this operation produced, linked via the free-form
     * `source_type`/`source_id` reference (not a foreign key) — matching how
     * {@see StockTransfer::movements()} and {@see InventoryReceipt} expose theirs, so the
     * read-only cross-module link never needs to reference {@see InventoryMovement} from the
     * Filament layer (P-2).
     *
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'source_id')
            ->where('source_type', 'inventory_operation');
    }

    public function isDraft(): bool
    {
        return $this->stage === OperationStage::Draft;
    }

    public function isWaiting(): bool
    {
        return $this->stage === OperationStage::Waiting;
    }

    public function isReady(): bool
    {
        return $this->stage === OperationStage::Ready;
    }

    public function isInTransit(): bool
    {
        return $this->stage === OperationStage::InTransit;
    }

    public function isDone(): bool
    {
        return $this->stage === OperationStage::Done;
    }

    public function isCanceled(): bool
    {
        return $this->stage === OperationStage::Canceled;
    }

    public function isTerminal(): bool
    {
        return $this->stage->isTerminal();
    }
}
