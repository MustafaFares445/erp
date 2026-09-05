<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryDocument;
use App\Enums\DeliveryType;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One warehouse movement document — a Receipt, Delivery or Internal Transfer sharing a single
 * Draft→Waiting→Ready→(InTransit→PartiallyReceived)→Done→Canceled lifecycle (FR-001, FR-002,
 * data-model.md §2).
 *
 * `stage`, `operation_number`, `dispatched_at`, `completed_at` and `canceled_at` are
 * service-owned — assigned only by {@see InventoryOperationService} — and therefore not
 * fillable. Operations are immutable and undeletable once `stage` is `Done` or `Canceled`
 * (V-04, enforced by the policy, not here).
 */
/**
 * @property int $id
 * @property int $destination_warehouse_id
 * @property Collection<int, InventoryOperationLine> $lines
 */
#[Fillable([
    'operation_type', 'source_warehouse_id', 'destination_warehouse_id', 'supplier_id',
    'customer_id', 'customer_delivery_address_id', 'source_document_type', 'source_document_id', 'supplier_reference', 'scheduled_at',
    'responsible_id', 'delivery_type', 'source_address_snapshot', 'destination_address_snapshot', 'notes',
])]
final class InventoryOperation extends Model implements HasMedia
{
    /** @use HasFactory<InventoryOperationFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;

    /**
     * Mirrors the `stage` column's DB-level default so observers and Filament forms may read
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
            'delivery_type' => DeliveryType::class,
            'stage' => OperationStage::class,
            'scheduled_at' => 'datetime',
            'source_address_snapshot' => 'array',
            'destination_address_snapshot' => 'array',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
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

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<CustomerDeliveryAddress, $this> */
    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerDeliveryAddress::class, 'customer_delivery_address_id');
    }

    public function registerMediaCollections(): void
    {
        foreach (DeliveryDocument::cases() as $document) {
            $this->addMediaCollection($document->value)->useDisk('local')->singleFile();
        }

    }

    /** @return array<DeliveryDocument> */
    public function missingDeliveryDocuments(): array
    {
        if ($this->operation_type !== OperationType::Delivery) {
            return [];
        }

        return array_values(array_filter(
            DeliveryDocument::cases(),
            fn (DeliveryDocument $document): bool => ! $this->getFirstMedia($document->value) instanceof Media,
        ));
    }

    public function hasCompleteDeliveryDocuments(): bool
    {
        return $this->missingDeliveryDocuments() === [];
    }

    public function stageLabel(): string
    {
        return $this->operation_type === OperationType::Delivery && $this->stage === OperationStage::Done
            ? __('admin.inventory.operation.stages.delivered')
            : $this->stage->label();
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

    /** @return HasOne<Shipment, $this> */
    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class, 'inventory_operation_id');
    }

    /**
     * The consolidated-invoicing link for this delivery (WP-2.13, GAP-MW-13), present once the
     * delivery has been invoiced — standalone or consolidated — and absent otherwise.
     *
     * @return HasOne<InvoiceDeliveryLink, $this>
     */
    public function invoiceDeliveryLink(): HasOne
    {
        return $this->hasOne(InvoiceDeliveryLink::class);
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
     * The ledger movements this canonical operation produced, linked via the free-form
     * `source_type`/`source_id` reference (not a foreign key), so the read-only cross-module
     * link never needs to reference {@see InventoryMovement} from the Filament layer (P-2).
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

    public function isPartiallyReceived(): bool
    {
        return $this->stage === OperationStage::PartiallyReceived;
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

    /**
     * Whether a delivery has already been invoiced — standalone or consolidated (WP-2.13,
     * GAP-MW-13). Meaningless for a receipt or internal transfer, which are never invoiced.
     */
    public function isInvoiced(): bool
    {
        if ($this->relationLoaded('invoiceDeliveryLink')) {
            return $this->invoiceDeliveryLink instanceof InvoiceDeliveryLink;
        }

        return $this->invoiceDeliveryLink()->exists();
    }
}
