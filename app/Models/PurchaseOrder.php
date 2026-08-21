<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OperationType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Models\Concerns\TracksBlameable;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use App\Services\Purchasing\PurchaseOrderService;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A commitment to buy goods from one supplier, delivered to one warehouse
 * (data-model.md §2).
 *
 * Every column that records *what happened to* the order — its number, its
 * status, its stored total, and every approval, transmission, closure, and
 * cancellation stamp — is absent from `#[Fillable]` on purpose (data-model.md
 * §10). They are written by {@see PurchaseOrderService} and
 * {@see PurchaseOrderApprovalService} through `forceFill()`, the same
 * discipline {@see InventoryOperation} applies to `stage` and
 * `operation_number`. A form that could mass-assign `status` would make the
 * transition matrix decorative.
 *
 * This model writes no stock. Receipts are {@see InventoryOperation} records
 * pointing back here through the existing `source_document` morph, and
 * `inventory_stocks` / `inventory_movements` are written only by the Inventory
 * services (R-001).
 *
 * @property int $id
 * @property string $purchase_order_number
 * @property int $supplier_id
 * @property int $destination_warehouse_id
 * @property PurchaseOrderStatus $status
 * @property string $currency_code
 * @property string $total_amount
 * @property int|null $submitted_by
 * @property int|null $approved_by
 * @property Carbon|null $sent_at
 * @property Carbon|null $cancelled_at
 * @property Supplier $supplier
 * @property Warehouse $destinationWarehouse
 * @property Collection<int, PurchaseOrderLine> $lines
 * @property Collection<int, InventoryOperation> $receipts
 * @property Collection<int, SupplierConfirmation> $confirmations
 */
#[Fillable([
    'supplier_id',
    'destination_warehouse_id',
    'currency_code',
    'ordered_at',
    'expected_at',
    'notes',
])]
final class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /**
     * Mirrors the column default so a freshly instantiated order reports its
     * status before it is persisted, matching {@see InventoryOperation}.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'ordered_at' => 'date',
            'expected_at' => 'date',
            'total_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The inventory operations that received against this order.
     *
     * Mirrors {@see Order::deliveries()} on the outbound side: same morph, same
     * shape, opposite direction.
     *
     * @return MorphMany<InventoryOperation, $this>
     */
    public function receipts(): MorphMany
    {
        return $this->morphMany(InventoryOperation::class, 'source_document')
            ->where('operation_type', OperationType::Receipt->value);
    }

    /** @return MorphMany<SupplierConfirmation, $this> */
    public function confirmations(): MorphMany
    {
        return $this->morphMany(SupplierConfirmation::class, 'confirmable');
    }

    /**
     * Whether the supplier's most recent answer was a rejection (FR-034).
     *
     * Deliberately not a status: a supplier declining an order is information
     * the buyer acts on, not a lifecycle transition. The order stays `sent` and
     * still receivable, because a supplier who says no by email and ships
     * anyway is a real thing that happens.
     */
    public function hasRejectedConfirmation(): bool
    {
        $latest = $this->confirmations()->latest('id')->first();

        return $latest?->confirmation_status === SupplierConfirmationStatus::Rejected;
    }

    /**
     * Whether any receipt has completed against this order.
     *
     * The gate on cancellation (V-13, FR-026): once stock has physically
     * arrived, voiding the order would leave that stock with no commitment
     * explaining it. The short-close path exists for this case instead.
     */
    public function hasCompletedReceipt(): bool
    {
        return $this->receipts()->whereNotNull('completed_at')->exists();
    }
}
