<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryReturnStatus;
use App\Enums\InventoryReturnType;
use Database\Factories\InventoryReturnFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'return_number',
    'return_type',
    'status',
    'warehouse_id',
    'customer_id',
    'supplier_id',
    'original_inventory_operation_id',
    'original_purchase_order_id',
    'reason',
    'notes',
    'financial_reference_type',
    'financial_reference_id',
    'cancellation_reason',
])]
final class InventoryReturn extends Model
{
    /** @use HasFactory<InventoryReturnFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'draft',
    ];

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $return): void {
            $rawStatus = $return->getRawOriginal('status');
            $original = is_string($rawStatus) ? InventoryReturnStatus::tryFrom($rawStatus) : null;

            if ($original?->isTerminal() === true) {
                throw new DomainException('Posted and cancelled inventory returns are immutable.');
            }

            if ($original === InventoryReturnStatus::Ready) {
                $allowed = ['status', 'posted_at', 'cancelled_at', 'cancellation_reason', 'updated_by', 'updated_at'];
                $forbidden = array_diff(array_keys($return->getDirty()), $allowed);

                if ($forbidden !== []) {
                    throw new DomainException(
                        'A ready inventory return is frozen; only posting or cancellation may change it.',
                    );
                }
            }
        });

        self::deleting(function (): never {
            throw new DomainException(
                'Inventory return documents cannot be deleted; use cancellation before posting or a compensating correction afterward.',
            );
        });
    }

    #[\Override]
    public function casts(): array
    {
        return [
            'return_type' => InventoryReturnType::class,
            'status' => InventoryReturnStatus::class,
            'ready_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<InventoryOperation, $this> */
    public function originalOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class, 'original_inventory_operation_id');
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function originalPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'original_purchase_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<InventoryReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryReturnLine::class);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'source_id')
            ->where('source_type', 'inventory_return');
    }

    public function isDraft(): bool
    {
        return $this->status === InventoryReturnStatus::Draft;
    }

    public function isReady(): bool
    {
        return $this->status === InventoryReturnStatus::Ready;
    }

    public function isPosted(): bool
    {
        return $this->status === InventoryReturnStatus::Posted;
    }

    public function isCancelled(): bool
    {
        return $this->status === InventoryReturnStatus::Cancelled;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
